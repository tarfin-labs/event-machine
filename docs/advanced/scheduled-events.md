# Scheduled Events

Scheduled events let you define cron-based batch operations that target **all matching machine instances**. Unlike `after`/`every` (per-instance timers), schedules query the database to find instances and dispatch events to each of them.

## Instance Scope

| Feature | Targets | How |
|---------|---------|-----|
| `endpoints` | **Single instance** | HTTP request includes root_event_id or model ID |
| `schedules` | **All matching instances** | Cron → resolver queries DB → batch dispatch |
| `after`/`every` | **Per-instance** | Timer fires for ONE instance based on state_entered_at |

## Design Principle: Definition vs Registration

Same pattern as endpoints:

```
endpoints:  Definition declares WHAT  →  MachineRouter in routes/ registers HOW
schedules:  Definition declares WHAT  →  MachineScheduler in routes/console.php registers WHEN + HOW
```

## Defining Schedules

Add a `schedules` key to `MachineDefinition::define()`:

<!-- doctest-attr: ignore -->
```php
MachineDefinition::define(
    config: [
        'on' => [
            'CHECK_EXPIRY' => ['target' => 'expired', 'guards' => 'isExpiredGuard'],
        ],
        'states' => [...],
    ],
    schedules: [
        // Class-based resolver (recommended)
        'CHECK_EXPIRY' => ExpiredApplicationsResolver::class,

        // Inline resolver (closure)
        'SEND_REMINDER' => fn () => Application::where('approved_at', '<=', now()->subDays(2))
                                                ->pluck('application_mre'),

        // No resolver — auto-detect states from idMap
        'DAILY_REPORT' => null,

        // EventBehavior FQCN as key
        CheckExpiryEvent::class => ExpiredApplicationsResolver::class,
    ],
)
```

### Schedule Entry Values

| Value | Type | Behavior |
|-------|------|----------|
| `ResolverClass::class` | `string` | Container-resolved, DI-supported, returns `Collection<string>` of root_event_ids |
| `fn () => ...` | `Closure` | Inline, returns `Collection<string>` of root_event_ids |
| `null` | `null` | Auto-detect: scan idMap for states handling event, query `machine_current_states` |

## Registering Schedules

Register in `routes/console.php` using `MachineScheduler`:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Scheduling\MachineScheduler;

MachineScheduler::register(ApplicationMachine::class, 'CHECK_EXPIRY')
    ->dailyAt('00:10')
    ->environments(['production', 'staging'])
    ->onOneServer();
```

`MachineScheduler::register()` returns Laravel's `SchedulingEvent`, so **all** Laravel Scheduler fluent methods are available:

<!-- doctest-attr: no_run -->
```php
MachineScheduler::register(ApplicationMachine::class, 'CHECK_EXPIRY')
    ->dailyAt('00:10')
    ->environments(['production', 'staging'])
    ->countries([Country::TR])                   // custom macro
    ->sentryMonitor('expire-older-applications') // 3rd party
    ->onOneServer()
    ->withoutOverlapping()
    ->runInBackground();
```

### Multiple Schedules

<!-- doctest-attr: no_run -->
```php
MachineScheduler::register(ApplicationMachine::class, 'CHECK_EXPIRY')
    ->dailyAt('00:10')
    ->onOneServer();

MachineScheduler::register(ApplicationMachine::class, 'SEND_REMINDER_2D')
    ->dailyAt('11:00')
    ->environments(['production']);

MachineScheduler::register(ApplicationMachine::class, 'SEND_REMINDER_5D')
    ->dailyAt('11:00')
    ->environments(['production']);
```

## ScheduleResolver

A resolver returns `Collection<string>` of root_event_ids. It owns the model knowledge — EventMachine receives only IDs.

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Contracts\ScheduleResolver;

class ExpiredApplicationsResolver implements ScheduleResolver
{
    public function __construct(
        private readonly ApplicationConfig $config,  // constructor DI
    ) {}

    public function __invoke(): \Illuminate\Support\Collection
    {
        return Application::query()
            ->where('created_at', '<=', now()->subDays($this->config->expiryDays))
            ->whereIn('status', [
                ApplicationStatus::APPROVED,
                ApplicationStatus::WAITING,
            ])
            ->pluck('application_mre');
    }
}
```

Resolvers are container-resolved, supporting constructor dependency injection.

**Naming convention:** `{Description}Resolver` suffix.
Examples: `ExpiredApplicationsResolver`, `UnpaidOrdersResolver`, `ActiveSubscriptionsResolver`.

## Auto-Detect (Null Resolver)

When the resolver is `null`, the command auto-detects target states:

1. Scans the definition's `idMap` for states that handle the event
2. If a root-level `on` handler exists, sends to **all** instances
3. Queries `machine_current_states` for matching instances

<!-- doctest-attr: ignore -->
```php
schedules: [
    'DAILY_REPORT' => null,  // auto-detect from idMap
],
```

## Safety Cross-Check

Resolver returns root_event_ids from a model query. Models may map to different machine classes conditionally. The command cross-checks with `machine_current_states`:

<!-- doctest-attr: no_run -->
```php
$rootEventIds = $resolver();

$validIds = MachineCurrentState::whereIn('root_event_id', $rootEventIds)
    ->where('machine_class', $machineClass)
    ->pluck('root_event_id');
```

This ensures only instances belonging to the correct machine class receive the event.

## How It Works

```
Machine Definition                          routes/console.php
  schedules: [                              MachineScheduler::register(X, 'CHECK_EXPIRY')
    'CHECK_EXPIRY' =>                           ->dailyAt('00:10')
      ExpiredApplicationsResolver::class        ->environments(['production'])
  ]                                             ->onOneServer()
        |                                           |
        |  (defines WHAT + WHICH)                   |  (defines WHEN + HOW)
        v                                           v
ProcessScheduledCommand (--class=X --event=CHECK_EXPIRY)
  1. Load definition, find resolver for CHECK_EXPIRY
  2. Has resolver? → run it → get root_event_ids → cross-check machine_class
     No resolver?  → auto-detect states → query machine_current_states
  3. Dispatch SendToMachineJob for each valid instance (Bus::batch)
        |
        v
SendToMachineJob (existing)
  -> Restore machine -> send(event)
  -> Machine's on-transition handles it (guards, actions, transitions)
```

## Edge Cases

| Scenario | Behavior |
|----------|----------|
| Schedule registered but not in definition | Command warns, nothing dispatched |
| Schedule in definition but not registered | Never runs — no cron trigger |
| Same event in schedules AND after/every | Independent, both valid |
| Resolver throws exception | Caught, logged, nothing dispatched |
| Resolver returns IDs for wrong machine class | Cross-check filters them out |
| No instances match | Empty dispatch, success exit |

## Infinite Loop Protection

Scheduled events are dispatched via queue. Each job is a separate macrostep — the depth counter resets. If a scheduled event triggers an `@always` loop, only that job fails. Other instances in the batch continue normally.

## Comparison with Endpoints

| Aspect | `endpoints` | `schedules` |
|--------|-----------|------------|
| Definition key | `endpoints: [...]` | `schedules: [...]` |
| Registration | `MachineRouter::register()` in routes | `MachineScheduler::register()` in routes/console.php |
| What definition declares | Event mapping | Event + resolver |
| What registration declares | Prefix, middleware, model | Cron, environments, macros |
| Instance scope | Single (by ID) | All matching (by resolver/auto-detect) |
