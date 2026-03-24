# Upgrading Guide

## Support Policy

Only the **latest major version** receives bug fixes, new features, and security patches. All previous versions are end of life.

| Version | Status |
|---------|--------|
| **9.x** | **Active** — bug fixes, features, security |
| 8.x and below | End of life — upgrade to latest |

**Why only latest?**

EventMachine evolved rapidly from v1 to v7 with a small team. Maintaining multiple branches is not sustainable. More importantly, the upgrade barrier is low: v4 through v7 have **zero breaking changes to machine definitions** — the only breaking changes were PHP/Laravel version requirements (v4) and behavior constructor resolution (v6). A typical multi-version upgrade takes minutes, not days.

::: tip Upgrading from any version
Each section below has step-by-step migration instructions with before/after examples. For multi-version jumps (e.g., v3 → v7), follow each guide in sequence. No data migration is required between any versions — the `machine_events` table format has not changed since v1.
:::

## From 8.x to 9.0

EventMachine v9.0 removes the `spatie/laravel-data` dependency. Context and event classes now use Laravel-native validation (`rules()`) and a built-in cast system instead of Spatie attributes.

### Breaking Change 1: Bag Mode Removed

Context bag mode (`'context' => [...]` with plain arrays) has been removed. You must use a typed context class extending `ContextManager`.

**Before (v8):**
```php
MachineDefinition::define(
    config: [
        'context' => ['count' => 0, 'items' => []],  // Bag mode — no longer supported
        'states' => [...],
    ],
);
```

**After (v9):**
```php
class OrderContext extends ContextManager
{
    public function __construct(
        public int $count = 0,
        public array $items = [],
    ) {}
}

MachineDefinition::define(
    config: [
        'context' => OrderContext::class,  // Typed context required
        'states' => [...],
    ],
);
```

### Breaking Change 2: Spatie Optional Removed

**Before (v8):**
```php
use Spatie\LaravelData\Optional;

class OrderContext extends ContextManager
{
    public function __construct(
        public int|Optional $quantity,
        public ?string|Optional $email,
    ) {
        parent::__construct();
        if ($this->quantity instanceof Optional) { $this->quantity = 0; }
    }
}
```

**After (v9):**
```php
class OrderContext extends ContextManager
{
    public function __construct(
        public int     $quantity = 0,
        public ?string $email    = null,
    ) {}
}
```

### Breaking Change 3: Validation Attributes → rules()

**Before (v8):**
```php
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\IntegerType;

class OrderContext extends ContextManager
{
    public function __construct(
        #[IntegerType] #[Min(0)]
        public int $quantity = 0,
    ) { parent::__construct(); }
}
```

**After (v9):**
```php
class OrderContext extends ContextManager
{
    public function __construct(
        public int $quantity = 0,
    ) {}

    public static function rules(): array
    {
        return [
            'quantity' => ['integer', 'min:0'],
        ];
    }
}
```

### Breaking Change 4: Cast/Transform System

**Before (v8):**
```php
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Attributes\WithTransformer;

class ApplicationContext extends ContextManager
{
    public function __construct(
        #[WithCast(EnumCast::class, type: SalesChannelType::class)]
        #[WithTransformer(EnumTransformer::class)]
        public Optional|SalesChannelType $salesChannelType,
        #[WithCast(ModelCast::class, type: Retailer::class)]
        #[WithTransformer(ModelTransformer::class)]
        public Optional|Retailer $retailer,
    ) { parent::__construct(); }
}
```

**After (v9):**
```php
class ApplicationContext extends ContextManager
{
    public function __construct(
        public ?SalesChannelType $salesChannelType = null,  // Auto: BackedEnum
        public ?Retailer $retailer = null,                   // Auto: Model
        public ?Money $totalCashPrice = null,                 // Via typeCasts() or config
        public ?Collection $orderItems = null,
    ) {}

    public static function casts(): array
    {
        return ['orderItems' => [OrderItemData::class]];  // Layer 1: per-property
    }

    public static function typeCasts(): array
    {
        return [Money::class => MoneyCast::class];  // Layer 2: per-type, class-level
    }
}

// Or register app-wide in config/machine.php:
// 'casts' => [Money::class => MoneyCast::class]  // Layer 3: per-type, app-wide
```

The 4-layer cast resolution order: `casts()` > `typeCasts()` > `config/machine.php` > auto-detect.

Auto-detected types (zero config): `Model`, `BackedEnum`, `DateTimeInterface`, `Arrayable`.

### Breaking Change 5: Event rules() Signature and Flat Keys

**Before (v8):**
```php
use Spatie\LaravelData\Support\Validation\ValidationContext;

public static function rules(ValidationContext $context): array
{
    return ['payload.amount' => ['required', 'integer']];
}
```

**After (v9):**
```php
public static function rules(): array
{
    return ['amount' => ['required', 'integer']];  // Flat keys, no 'payload.' prefix
}
```

::: warning Flat Validation Keys
Event `rules()` now use flat keys (`'amount'`, not `'payload.amount'`). This applies to both typed and untyped events. Search your codebase for `payload.` in event rules and remove the prefix.
:::

### Breaking Change 6: ModelTransformer Removed

`src/Transformers/ModelTransformer.php` has been deleted. Model serialization is now handled automatically by the cast system's auto-detect layer.

### Breaking Change 7: `registerCast()` Removed

The `ContextManager::registerCast()` static method has been removed. Use `typeCasts()` on your class or register casts in `config/machine.php`:

**Before (v8/v9-early):**
```php
// AppServiceProvider
ContextManager::registerCast(Money::class, MoneyCast::class);
```

**After (v9):**
```php
// Option A: Per-class via typeCasts()
class OrderContext extends ContextManager
{
    public static function typeCasts(): array
    {
        return [Money::class => MoneyCast::class];
    }
}

// Option B: App-wide via config/machine.php
// 'casts' => [Money::class => MoneyCast::class]
```

### Breaking Change 8: `$event->payload` Returns Null on Typed Events

On typed events (events with constructor properties), the `$event->payload` property returns `null`. Use the `$event->payload()` method instead, which works for both typed and untyped events.

**Before:**
```php
$data = $event->payload['key'];
```

**After:**
```php
$data = $event->payload()['key'];

// Or for typed events, access properties directly:
$data = $event->key;
```

### New in v9.0

| Feature | Description |
|---------|-------------|
| **TypedData base class** | Shared base for `ContextManager` and `EventBehavior` — provides `from()`, `toArray()`, cast resolution, and validation |
| **Typed events** | Event subclasses can declare constructor properties for typed payload access (`$event->productId` instead of `$event->payload()['productId']`) |
| **`typeCasts()` method** | Per-type cast overrides at the class level (Layer 2 of 4-layer resolution) |
| **`config/machine.php` casts** | App-wide type cast registration (Layer 3 of 4-layer resolution) |
| **`$event->payload()` method** | Canonical accessor that works for both typed and untyped events |
| **4-layer cast resolution** | `casts()` > `typeCasts()` > `config/machine.php` > auto-detect |

### Migration Steps

1. `composer require tarfin-labs/event-machine:^9.0`
2. Remove `spatie/laravel-data` from your `composer.json` (unless you use it elsewhere)
3. Remove `LaravelDataServiceProvider` from test setup
4. **Bag mode:** Replace `'context' => [...]` arrays with typed `ContextManager` subclasses
5. **Context classes:** Remove Spatie imports/attributes, add `rules()` if needed, replace `Optional` with nullable defaults
6. **Event classes:** Remove `ValidationContext` parameter from `rules()` methods; change `payload.xxx` keys to flat `xxx` keys
7. **Custom casts:** Implement `ContextCast` interface instead of separate Spatie Cast + Transformer
8. **Global casts:** Replace `ContextManager::registerCast()` calls with either `typeCasts()` on the class or `config/machine.php` `casts` section
9. **`instanceof Optional` checks:** Replace with `=== null`
10. **Event payload access:** Use `$event->payload()` method instead of `$event->payload` property (the property returns `null` on typed events)
11. Run tests: `composer test`

### What Did NOT Change

| Feature | Status |
|---------|--------|
| `$context->property` (read/write) | Same |
| `$context->machineId()` | Same |
| `Machine::create()` / `::send()` | Same |
| Machine config format | Same |
| Action/Guard/Calculator signatures | Same |
| Event `getType()`, `payload()`, `type` | Same |
| `selfValidate()` / `validateAndCreate()` | Same API, new internal |
| `from()` / `toArray()` | Same API, new internal |
| State persistence (machine_events) | Same format |
| Child delegation | Same |
| Timers, schedules, endpoints | Same |

## From 8.5.4 to 8.6.0

### Computed Context in API Responses

Custom context classes can now expose computed values in endpoint responses by overriding `computedContext()`. These values are included in API responses but **not** persisted to the database.

**New methods on `ContextManager`:**

<!-- doctest-attr: ignore -->
```php
class OrderContext extends ContextManager
{
    public function __construct(
        public array $items = [],
        public float $total = 0.0,
    ) {
        parent::__construct();
    }

    protected function computedContext(): array
    {
        return [
            'item_count' => count($this->items),
            'is_empty'   => empty($this->items),
        ];
    }
}
```

The computed values appear in endpoint responses and `State::toArray()`, but are excluded from `machine_events` persistence. See [Exposing Computed Values](https://eventmachine.dev/advanced/custom-context#exposing-computed-values-in-api-responses) for details.

**No action required** — this is a purely additive feature. Existing context classes without `computedContext()` are unaffected.

## From 8.5.2 to 8.5.3

### processPostEntryTransitions Centralized into enterState()

`processPostEntryTransitions()` is now called internally by `enterState()` — individual callers no longer need to call it separately.

**If you subclass `MachineDefinition`** and call `processPostEntryTransitions()` directly, remove those calls. `enterState()` handles it automatically via the `processPostEntry` parameter (default `true`).

**If you don't subclass `MachineDefinition`**, no action needed.

### Dispatch Mode Parallel @done Fix

Entry actions that called `$this->raise()` on states entered via parallel `@done` in async/dispatch mode were silently lost. The event was queued but never processed. This is now fixed — raised events are processed in all code paths.

## From 8.5.3 to 8.5.4

### ResultBehavior Now Receives the Original Event

`Machine::result()` and `MachineController::resolveAndRunResult()` previously passed the last internal event (with NULL payload) to `ResultBehavior`. They now pass `$state->triggeringEvent` — the original external event with full payload.

**Before (broken):**

<!-- doctest-attr: ignore -->
```php
class CustomerDetailResult extends ResultBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): array
    {
        // $event->payload was NULL — it was an internal event
        return ['tckn' => $event->payload['tckn']]; // ❌ crash
    }
}
```

**After (fixed):**

<!-- doctest-attr: ignore -->
```php
class CustomerDetailResult extends ResultBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): array
    {
        // $event is now the original triggering event with full payload
        return ['tckn' => $event->payload()['tckn']]; // ✅ works
    }
}
```

**If you were working around NULL payloads** (e.g., reading from context instead of event), you can now read directly from the event.

## From 8.4.x to 8.5.0

### Testing Entry Point Simplification

`Machine::test()` and `Machine::startingAt()` are now the **only** entry points for class-based machine testing. `TestMachine` class-accepting static factories are marked `@internal`.

| Before | After |
|--------|-------|
| `TestMachine::create(MyMachine::class)` | `MyMachine::test()` |
| `TestMachine::create(MyMachine::class, ['key' => 'val'])` | `MyMachine::test(context: ['key' => 'val'])` |
| `TestMachine::withContext(MyMachine::class, [...])` | `MyMachine::test(context: [...])` |
| `TestMachine::withContext(MyMachine::class, [...], guards: [...])` | `MyMachine::test(context: [...], guards: [...])` |
| `TestMachine::startingAt(MyMachine::class, 'state', [...])` | `MyMachine::startingAt('state', context: [...])` |
| `MyMachine::withContext([...])` | `MyMachine::test(context: [...])` |

**Behavior change:** `Machine::test(context: [...])` now merges context **before** initialization (entry actions see it). Previously `Machine::test()` applied context post-init.

**`TestMachine::define()` is unchanged** — use it for inline throwaway machines without a Machine class.

**Find-replace migration:**

<!-- doctest-attr: ignore -->
```
// In your test files:
// TestMachine::create(XMachine::class, ...)   → XMachine::test(...)
// TestMachine::withContext(XMachine::class, ...) → XMachine::test(context: ...)
// TestMachine::startingAt(XMachine::class, ...) → XMachine::startingAt(...)
// XMachine::withContext([...])                → XMachine::test(context: [...])
```

## Version Compatibility

| EventMachine | PHP | Laravel | Status |
|--------------|-----|---------|--------|
| **8.x** | 8.3+ | 11.x, 12.x | **Active** |
| 7.x | 8.3+ | 11.x, 12.x | End of life |
| 6.x | 8.3+ | 11.x, 12.x | End of life |
| 5.x | 8.3+ | 11.x, 12.x | End of life |
| 4.x | 8.3+ | 11.x, 12.x | End of life |
| 3.x | 8.2+ | 10.x, 11.x, 12.x | End of life |
| 2.x | 8.1+ | 9.x, 10.x | End of life |
| 1.x | 8.0+ | 8.x, 9.x | End of life |

## Getting Help

If you encounter issues during upgrade:

1. Check the [GitHub Issues](https://github.com/tarfinlabs/event-machine/issues)
2. Review the [Changelog](https://github.com/tarfinlabs/event-machine/blob/main/CHANGELOG.md)
3. Open a new issue with your upgrade scenario

---

## Upgrading to v8.0

v8.0 has **one breaking change**: behaviors on `@always` transitions now receive the **original triggering event** instead of the synthetic `@always` event.

### Breaking Change: Event Preservation Through @always

In v7 and earlier, actions, guards, and calculators on `@always` transitions received a synthetic event with `type: '@always'` and `payload: null`. In v8, they receive the original event that triggered the macrostep.

**Before (v7):**

<!-- doctest-attr: ignore -->
```php
// Action on @always transition
class MyAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): void
    {
        $event->type;    // '@always'
        $event->payload; // null — payload lost!
    }
}
```

**After (v8):**

<!-- doctest-attr: ignore -->
```php
// Same action, same @always transition — now receives the real event
class MyAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): void
    {
        $event->type;    // 'ORDER_SUBMITTED' (the original event)
        $event->payload; // ['tckn' => '123...'] (preserved!)
    }
}
```

### Who Is Affected?

You are affected **only if** your behaviors on `@always` transitions check `$event->type === '@always'` or rely on `$event->payload` being `null`. This is uncommon — most `@always` behaviors use only `ContextManager` and ignore the event.

**Quick check:** Search your codebase for behaviors referenced by `@always` transitions that type-hint `EventBehavior`. If none do, the upgrade is seamless.

### Migration Steps

1. Update `composer.json`:

<!-- doctest-attr: ignore -->
```php
"tarfin-labs/event-machine": "^8.0"
```

2. Search for behaviors on `@always` transitions that use `EventBehavior`:
   - If they check `$event->type === '@always'` → remove the check (they now receive the real event type)
   - If they rely on `$event->payload` being `null` → update to handle the real payload

3. Run your tests. If all pass, you're done.

### What Did NOT Change

| Aspect | v8 Behavior |
|--------|-------------|
| `@always` routing mechanism | Unchanged — `findTransitionDefinition()` still uses `@always` key |
| Internal event history | Unchanged — `@always` still recorded in history |
| `$state->currentEventBehavior` | Unchanged — still tracks internal event markers |
| Listeners on transient states | Unchanged — still skipped |
| Infinite loop protection | Unchanged — depth limit still applies |
| Database schema | Unchanged — no migration needed |

For full details on event preservation, see [@always Transitions — Event Preservation](/advanced/always-transitions#event-preservation).

### New Feature: Raise Actor Auto-Propagation

v8.0 also introduces **automatic actor propagation** for raised events. When an action calls `raise()`, the actor from the triggering event is automatically inherited if not explicitly set.

**Before (v7):**

<!-- doctest-attr: ignore -->
```php
$this->raise(new ApprovedEvent(
    payload: $data,
    actor: $event->actor($context),  // had to pass manually
));
```

**After (v8):**

<!-- doctest-attr: ignore -->
```php
$this->raise(new ApprovedEvent(
    payload: $data,
    // actor auto-inherited from triggering event
));
```

This is **not a breaking change** — existing code that explicitly passes `actor:` continues to work. The explicit value always takes precedence.

For details, see [Raised Events — Actor Propagation](/advanced/raised-events#actor-propagation).

### New Feature: Endpoint Filtering (`only` / `except`)

`MachineRouter::register()` now accepts `only` and `except` options to control which event endpoints are registered per route group. This enables splitting the same machine's endpoints across different middleware groups (e.g., public vs authenticated):

<!-- doctest-attr: ignore -->
```php
// Public — customer-facing, no auth
MachineRouter::register(CarSalesMachine::class, [
    'prefix' => 'car-sales',
    'only'   => [ConsentGrantedEvent::class, PersonalInfoSubmittedEvent::class],
    'name'   => 'car-sales.public',
]);

// Protected — retailer panel, auth required
MachineRouter::register(CarSalesMachine::class, [
    'prefix'     => 'machines/car-sales',
    'middleware'  => ['auth:retailer'],
    'except'     => [ConsentGrantedEvent::class, PersonalInfoSubmittedEvent::class],
    'name'       => 'machines.car-sales',
]);
```

For details, see [Endpoint Filtering](https://eventmachine.dev/laravel-integration/endpoints#endpoint-filtering).

### Stricter Validation: `machineIdFor` / `modelFor`

`MachineRouter::register()` now validates that event types in `machineIdFor` and `modelFor` exist in the registered endpoint set. Previously, referencing a nonexistent or forwarded event type was silently ignored — now it throws an `InvalidArgumentException` with a specific error message. This surfaces pre-existing misconfigurations.

---

## Upgrading to v7.0

v7.0 is a major feature release with **no breaking changes**. All existing machines continue to work unchanged. New capabilities:

- **Machine Delegation** — invoke child machines via `machine`/`job` keys with `@done`/`@fail` lifecycle
- **Cross-Machine Communication** — `sendTo()`, `dispatchTo()`, `sendToParent()`, `dispatchToParent()`, `raise()`
- **Time-Based Events** — `after` (one-shot) and `every` (recurring) timers on transitions
- **Scheduled Events** — cron-based batch operations via `schedules` key and `MachineScheduler`
- **Machine Faking** — short-circuit child machines in tests
- **Machine Identity** — `$context->machineId()` and `$context->parentMachineId()`
- **Infinite Loop Protection** — Configurable `max_transition_depth` (default 100) prevents stack overflow from `@always` loops and `raise()` cycles. See [Infinite Loop Protection](/advanced/always-transitions#infinite-loop-protection)

### New Feature: Machine Delegation

A state can now invoke a child machine via the `machine` key. The child runs its own lifecycle, and when it completes, the parent's `@done` or `@fail` transition fires.

<!-- doctest-attr: ignore -->
```php
'processing_payment' => [
    'machine' => PaymentMachine::class,
    'with'    => ['order_id', 'total_amount'],
    '@done'   => 'shipping',
    '@fail'   => 'payment_failed',
],
```

**Two execution modes:**
- **Sync (default):** Child runs inline within the parent's transition. Simplest option.
- **Async (queue):** Child runs on a Laravel queue worker. Parent stays in the delegating state until completion.

<!-- doctest-attr: ignore -->
```php
// Async: child dispatched to queue
'processing_payment' => [
    'machine'  => PaymentMachine::class,
    'queue'    => 'payments',
    '@done'    => 'shipping',
    '@fail'    => 'payment_failed',
    '@timeout' => [
        'after'  => 300,
        'target' => 'payment_timed_out',
    ],
],
```

For full documentation, see [Machine Delegation](/advanced/machine-delegation).

### New Feature: Cross-Machine Communication

Behaviors can now send events to other machine instances. Sync methods (`sendTo`, `sendToParent`) deliver immediately. Async methods (`dispatchTo`, `dispatchToParent`) dispatch via queue:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class ReportProgressAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        // Async: dispatch progress to parent via queue
        $this->dispatchToParent($context, [
            'type'    => 'CHILD_PROGRESS',
            'payload' => ['percent' => 50],
        ]);

        // Async: dispatch event to any machine via queue
        $this->dispatchTo(
            machineClass: TargetMachine::class,
            rootEventId: $context->get('target_id'),
            event: ['type' => 'NOTIFICATION'],
        );

        // Sync: send event immediately (blocking)
        $this->sendTo(
            machineClass: TargetMachine::class,
            rootEventId: $context->get('target_id'),
            event: ['type' => 'URGENT_NOTIFICATION'],
        );
    }
}
```

For full documentation, see [Cross-Machine Messaging](/advanced/sendto) and [Inter-Machine Testing](/testing/delegation-testing).

### New Feature: Machine Faking

Short-circuit child machines in tests — no child actually runs:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Actor\Machine;

PaymentMachine::fake(result: ['payment_id' => 'pay_123']);

$machine = OrderWorkflowMachine::create();
$machine->send(['type' => 'START']);

PaymentMachine::assertInvoked();
PaymentMachine::assertInvokedWith(['order_id' => 'ORD-1']);

Machine::resetMachineFakes();
```

### New Feature: Machine Identity

Every machine now has access to its own identity via `$context->machineId()`. Child machines also know their parent via `$context->parentMachineId()`.

### New Feature: XState Export for Delegation

The `machine:xstate` Artisan command now maps `machine` keys to XState v5 `invoke` blocks, enabling visualization in [Stately Studio](https://stately.ai).

### New Config Keys

| Key | Type | Description |
|-----|------|-------------|
| `machine` | `string` (FQCN) | Child machine class to invoke |
| `with` | `array\|Closure` | Data to pass from parent to child context |
| `@done` | `string\|array` | Transition when child reaches final state |
| `@fail` | `string\|array` | Transition when child fails |
| `@timeout` | `array` | Transition when child times out (async only) |
| `queue` | `bool\|string\|array` | Run child on a Laravel queue |
| `forward` | `array` | Event types to forward from parent to running child |
| `on` | `array` | Additional events the parent can handle while child is running |
| `job` | `string` (FQCN) | Laravel Job class to invoke as actor |
| `target` | `string` | Target state for fire-and-forget (jobs and machine delegation) |
| `output` | `array\|Closure` | Filter child context exposed to parent via `@done` |
| `after` | `Timer` | One-shot timer on transition (auto-trigger after duration) |
| `every` | `Timer` | Recurring timer on transition (auto-trigger at interval) |
| `max` | `int` | Max fire count for `every` timer |
| `then` | `string` | Event to send after `max` reached |
| `schedules` | `array` | Schedule definitions: event → resolver mapping |

### New Feature: Time-Based Events

Define `after` and `every` timers directly on transitions. The sweep command auto-discovers machines and processes timers:

<!-- doctest-attr: ignore -->
```php
'awaiting_payment' => [
    'on' => [
        'PAY'           => 'processing',
        'ORDER_EXPIRED' => ['target' => 'cancelled', 'after' => Timer::days(7)],
        'REMINDER'      => ['actions' => 'sendReminderAction', 'every' => Timer::days(1)],
    ],
],
```

For full documentation, see [Time-Based Events](/advanced/time-based-events) and [Time-Based Testing](/testing/time-based-testing).

### New Feature: Scheduled Events

Define cron-based batch operations that target all matching machine instances. The `schedules` key on `MachineDefinition::define()` pairs event types with resolvers:

<!-- doctest-attr: ignore -->
```php
MachineDefinition::define(
    config: [...],
    schedules: [
        'CHECK_EXPIRY' => ExpiredApplicationsResolver::class,
        'DAILY_REPORT' => null,  // auto-detect from idMap
    ],
)
```

Register the cron schedule in `routes/console.php`:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Scheduling\MachineScheduler;

MachineScheduler::register(ApplicationMachine::class, 'CHECK_EXPIRY')
    ->dailyAt('00:10')
    ->onOneServer();
```

For full documentation, see [Scheduled Events](/advanced/scheduled-events) and [Scheduled Testing](/testing/scheduled-testing).

### New Files

| File | Description |
|------|-------------|
| `src/Behavior/ChildMachineDoneEvent.php` | Typed event for `@done` with result/context accessors |
| `src/Behavior/ChildMachineFailEvent.php` | Typed event for `@fail` with error accessors |
| `src/Definition/MachineInvokeDefinition.php` | Value object for machine delegation config |
| `src/Jobs/ChildMachineJob.php` | Queue job for async child creation |
| `src/Jobs/ChildMachineCompletionJob.php` | Queue job for routing `@done`/`@fail` back to parent |
| `src/Jobs/ChildMachineTimeoutJob.php` | Delayed check job for `@timeout` |
| `src/Jobs/SendToMachineJob.php` | Queue job for `dispatchTo()` / `dispatchToParent()` |
| `src/Jobs/ChildJobJob.php` | Queue job for job actor execution |
| `src/Contracts/ReturnsResult.php` | Interface for jobs that return output to parent |
| `src/Models/MachineChild.php` | Eloquent model for async child tracking |
| `src/Models/MachineCurrentState.php` | Tracks current state of machine instances |
| `src/Models/MachineTimerFire.php` | Timer dedup and recurring state tracking |
| `src/Support/Timer.php` | Duration value object for timer config |
| `src/Definition/TimerDefinition.php` | Parsed timer config from transitions |
| `src/Enums/TimerResolution.php` | Sweep frequency enum |
| `src/Commands/ProcessTimersCommand.php` | Sweep command for time-based events |
| `src/Commands/TimerStatusCommand.php` | Timer status display |
| `src/Commands/MachineCacheCommand.php` | Cache machine discovery for production |
| `src/Commands/MachineClearCommand.php` | Clear machine discovery cache |
| `src/Contracts/ScheduleResolver.php` | Interface for schedule instance resolution |
| `src/Definition/ScheduleDefinition.php` | Value object for schedule config |
| `src/Scheduling/MachineScheduler.php` | Registration API for scheduled events |
| `src/Commands/ProcessScheduledCommand.php` | Processes scheduled events for machine instances |

### New Database Tables

v7.0 adds three new tables:

| Table | Purpose |
|-------|---------|
| `machine_children` | Tracks async child machine instances (delegation with `queue` key) |
| `machine_current_states` | Normalized current state per machine instance (for timers and scheduled events) |
| `machine_timer_fires` | Timer dedup and recurring fire tracking (`after`/`every` transitions) |

Publish and run migrations:

```bash
php artisan vendor:publish --tag=machine-migrations
php artisan migrate
```

::: info
Tables are only populated when their respective features are used. If you only use basic state machines without delegation, timers, or schedules, the tables will remain empty but should still be created.
:::

### New Artisan Commands

| Command | Purpose |
|---------|---------|
| `machine:process-timers` | Sweep command for `after`/`every` timers (auto-registered via ServiceProvider) |
| `machine:process-scheduled` | Processes scheduled events (called by `MachineScheduler` via Laravel Scheduler) |
| `machine:timer-status` | Display timer status for machine instances |
| `machine:cache` | Cache machine class discovery for production |
| `machine:clear` | Clear machine discovery cache |

### New Testing Helpers

| Helper | Purpose |
|--------|---------|
| `advanceTimers(Timer $duration)` | Backdate state entry and run timer sweep inline |
| `processTimers()` | Run timer sweep without time change |
| `assertHasTimer(string $event)` | Assert timer exists on current state |
| `assertTimerFired(string $event)` | Assert timer has fired |
| `assertTimerNotFired(string $event)` | Assert timer has NOT fired |
| `runSchedule(string $event)` | Send scheduled event inline (bypasses queue) |
| `assertHasSchedule(string $event)` | Assert schedule exists in definition |

### New Internal Events

| Event | Purpose |
|-------|---------|
| `CHILD_MACHINE_STARTED` | Child machine created and running |
| `CHILD_MACHINE_DONE` | Child reached final state |
| `CHILD_MACHINE_FAILED` | Child threw an exception |
| `CHILD_MACHINE_CANCELLED` | Parent left delegating state, child cancelled |
| `CHILD_MACHINE_TIMED_OUT` | Child did not complete within `@timeout` period |

### Migration Steps

#### Step 1: Update Dependencies

```bash
composer require tarfinlabs/event-machine:^7.0
```

#### Step 2: Publish and Run Migrations

```bash
php artisan vendor:publish --tag=machine-migrations
php artisan migrate
```

This creates the following new tables:
- `machine_children` — Async child machine tracking
- `machine_current_states` — Current state tracking (required for time-based events)
- `machine_timer_fires` — Timer dedup and recurring fire tracking

#### Step 3: Start Using Features (Optional)

No existing code needs to change. Add `machine`/`job` keys, `after`/`every` timers, and `output` keys when you're ready.

### Forward-Aware Endpoints (Breaking Change)

::: warning Breaking Change
If you have parent machines that manually duplicate forwarded child events in their own `behavior.events` and `endpoints`, you must migrate. The validator now **rejects overlap** between forward config and parent endpoints/behavior.events.
:::

#### What Changed

Forward events declared in a state's `forward` config are now **auto-discovered** from the child machine's definition. The parent no longer needs to:
- Redeclare the child's `EventBehavior` class in its own `behavior.events`
- Add the forwarded event to the parent's `endpoints` array

The child's event definitions are the **single source of truth**. If the same event type appears in both the `forward` config AND the parent's `endpoints` or `behavior.events`, the `MachineDefinition` validator throws an `InvalidArgumentException`.

#### Why

Previously, forwarded events required manual duplication across three locations: the child's `behavior.events`, the parent's `behavior.events`, and the parent's `endpoints`. This created ambiguity about which `EventBehavior` class to use for validation, and silent bugs when the parent and child event classes diverged.

With forward-aware endpoints, there is a single source of truth: the `forward` key on the delegating state. The parent auto-generates endpoints from the child's definition.

#### Migration Steps

**1. Find parent machines with `forward` config:**

Search for states that use the `forward` key in machine definitions.

**2. Remove forwarded events from `behavior.events`:**

```php no_run
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

// BEFORE — duplicated event class in parent's behavior.events
class PaymentFlowMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'payment_flow',
                'initial' => 'collecting',
                'context' => ['order_id' => null],
                'states'  => [
                    'collecting' => [
                        'on' => ['START' => 'processing'],
                    ],
                    'processing' => [
                        'machine' => PaymentChildMachine::class,
                        'queue'   => 'payments',
                        'forward' => ['PROVIDE_CARD'],
                        '@done'   => 'completed',
                    ],
                    'completed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'START'        => StartEvent::class,
                    'PROVIDE_CARD' => ProvideCardEvent::class, // REMOVE — auto-discovered from child
                ],
            ],
        );
    }
}
```

```php no_run
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

// AFTER — forward auto-discovers child's EventBehavior
class PaymentFlowMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'payment_flow',
                'initial' => 'collecting',
                'context' => ['order_id' => null],
                'states'  => [
                    'collecting' => [
                        'on' => ['START' => 'processing'],
                    ],
                    'processing' => [
                        'machine' => PaymentChildMachine::class,
                        'queue'   => 'payments',
                        'forward' => ['PROVIDE_CARD'],
                        '@done'   => 'completed',
                    ],
                    'completed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'START' => StartEvent::class,
                    // PROVIDE_CARD removed — child owns it
                ],
            ],
        );
    }
}
```

**3. Remove forwarded events from the `endpoints` array:**

```php no_run
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

// BEFORE — PROVIDE_CARD in both forward and endpoints
class PaymentFlowMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'payment_flow',
                'initial' => 'collecting',
                'context' => ['order_id' => null],
                'states'  => [
                    'collecting' => [
                        'on' => ['START' => 'processing'],
                    ],
                    'processing' => [
                        'machine' => PaymentChildMachine::class,
                        'queue'   => 'payments',
                        'forward' => ['PROVIDE_CARD'],
                        '@done'   => 'completed',
                    ],
                    'completed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'START' => StartEvent::class,
                ],
            ],
            endpoints: [
                'START',
                'PROVIDE_CARD', // REMOVE — auto-generated from forward
            ],
        );
    }
}
```

```php no_run
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

// AFTER — only parent events in endpoints
class PaymentFlowMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'payment_flow',
                'initial' => 'collecting',
                'context' => ['order_id' => null],
                'states'  => [
                    'collecting' => [
                        'on' => ['START' => 'processing'],
                    ],
                    'processing' => [
                        'machine' => PaymentChildMachine::class,
                        'queue'   => 'payments',
                        'forward' => ['PROVIDE_CARD'],
                        '@done'   => 'completed',
                    ],
                    'completed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'START' => StartEvent::class,
                ],
            ],
            endpoints: [
                'START',
                // PROVIDE_CARD removed — forward generates its endpoint automatically
            ],
        );
    }
}
```

**4. Move customization to Format 3 (if needed):**

If you had custom URI, middleware, or result behavior on the forwarded endpoint, move that configuration into the `forward` array using Format 3 (full config):

```php ignore
// Format 3: full config for forward entries
'forward' => [
    'PROVIDE_CARD' => [
        'uri'        => '/custom-card-endpoint',
        'method'     => 'PUT',
        'action'     => CustomForwardAction::class,
        'result'     => PaymentStepResult::class,
        'middleware'  => ['auth:sanctum'],
        'contextKeys' => ['card_last4', 'status'],
        'status'     => 201,
    ],
],
```

**5. Update tests for the new response format:**

Forwarded endpoint responses now include both parent and child state:

```json
// BEFORE — parent-only response
{
    "data": {
        "machine_id": "root-event-id",
        "value": ["payment_flow.processing"],
        "context": { "order_id": 1 }
    }
}
```

```json
// AFTER — parent + child response
{
    "data": {
        "machine_id": "root-event-id",
        "value": ["payment_flow.processing"],
        "child": {
            "value": ["payment_child.awaiting_confirmation"],
            "context": { "card_last4": "4242" }
        }
    }
}
```

Update any test assertions that check forwarded endpoint response structure to expect the `child` key in the response body.

---

## Upgrading to v6.0

v6.0 introduces a comprehensive testability layer with three breaking changes to behavior resolution. Most applications require **no code changes** — the breaking changes only affect behaviors with custom constructors.

### Breaking Changes

#### 1. Behavior Resolution via Container

Behaviors are now resolved through Laravel's service container (`App::make()`) instead of direct instantiation (`new $class()`). This enables constructor dependency injection.

**Before:**
<!-- doctest-attr: ignore -->
```php
// MachineDefinition::getInvokableBehavior()
return new $behaviorDefinition($this->eventQueue);
```

**After:**
<!-- doctest-attr: ignore -->
```php
return App::make($behaviorDefinition, ['eventQueue' => $this->eventQueue]);
```

**Action required if you:**

1. Override `InvokableBehavior::__construct()` with non-injectable parameters (plain `string`, `int`, `array` without defaults):
   → Register a container binding for the parameter.

2. Extend constructors positionally:
   → Use named, typed parameters.

**No action required if you:**
- Use the default `InvokableBehavior` constructor (most behaviors)
- Only override `__invoke()` (most behaviors)
- Use typed service dependencies in constructors (new capability!)

**Example migration:**

<!-- doctest-attr: ignore -->
```php
// ❌ BREAKS — container can't resolve `string $prefix` automatically
class BadBehavior extends ActionBehavior
{
    public function __construct(string $prefix, ?Collection $eventQueue = null)
    {
        parent::__construct($eventQueue);
    }
}

// ✅ FIX — use a typed dependency or register a binding
class FixedBehavior extends ActionBehavior
{
    public function __construct(PrefixConfig $config, ?Collection $eventQueue = null)
    {
        parent::__construct($eventQueue);
    }
}
// OR: register in a service provider
$this->app->when(BadBehavior::class)->needs('$prefix')->give('my_prefix');
```

::: info
The `❌ BREAKS` scenario was already broken with `new $class($eventQueue)` — a Collection would be passed as the `$prefix` string parameter, causing a type mismatch. Container resolution **surfaces existing bugs** rather than creating new ones.
:::

#### 2. `InvokableBehavior::run()` Always Uses Container

**Before:**
<!-- doctest-attr: ignore -->
```php
$instance = static::isFaked() ? App::make(static::class) : new static();
```

**After:**
<!-- doctest-attr: ignore -->
```php
$instance = App::make(static::class);
```

**Who is affected:** Code that calls `::run()` and relies on `new static()` bypassing the container. In practice, no one — this method was only used in tests.

#### 3. `Fakeable::fake()` Uses `App::bind()` with Closure

`Fakeable::fake()` now registers fakes via `App::bind($class, fn() => $mock)` instead of the previous dual storage approach. The closure always returns the same Mockery mock instance.

**Why `App::bind()` instead of `App::instance()`:** Laravel's `App::instance()` bindings are **ignored** when `App::make()` is called with explicit parameters (e.g., `App::make(MyGuard::class, ['eventQueue' => $queue])`). Since `getInvokableBehavior()` always passes `['eventQueue' => $this->eventQueue]`, `App::instance()` would silently bypass the fake.

**Cleanup change:** `resetFakes()` now uses `app()->offsetUnset($class)` instead of `App::forgetInstance($class)`, because `forgetInstance()` only removes instance bindings, not closure bindings. The public API is identical.

### New Testing Features

| Feature | Description |
|---------|-------------|
| `Machine::test()` | Livewire-style fluent test wrapper with 21+ assertion methods |
| `State::forTesting()` | Lightweight state factory for unit tests |
| `InvokableBehavior::runWithState()` | Isolated testing with engine-identical DI. For void actions, returns the `eventQueue` Collection so raised events are accessible. |
| `EventBehavior::forTesting()` | Test factory for event construction |
| Constructor DI | Behaviors can now inject service dependencies via `__construct()` |
| `Fakeable::spy()` | Permissive mock that records all calls silently |
| `Fakeable::allowToRun()` | Spy mode — allows and records `__invoke` calls |
| `Fakeable::assertRanWith()` | Assert `__invoke` was called with matching arguments |
| `Fakeable::assertRanTimes()` | Assert `__invoke` was called exactly N times |
| `Fakeable::mayReturn()` | Set return value without "at least once" call expectation |

### Migration Steps

#### Step 1: Update Dependencies

```bash
composer require tarfinlabs/event-machine:^6.0
```

#### Step 2: Check Custom Constructors

Search for behaviors that override `__construct()`:

```bash
grep -r "extends ActionBehavior\|extends GuardBehavior\|extends CalculatorBehavior" --include="*.php" -l | xargs grep "__construct"
```

If any have non-injectable parameters (plain `string`, `int`, `array` without type-hinted objects), register a binding:

<!-- doctest-attr: ignore -->
```php
// In a service provider
$this->app->when(MyBehavior::class)->needs('$paramName')->give('value');
```

#### Step 3: Update Test Fake Cleanup

If you use `Fakeable::fake()` in tests, ensure `resetAllFakes()` is called in `afterEach` or Pest's global `afterEach` hook:

<!-- doctest-attr: ignore -->
```php
// tests/Pest.php
afterEach(function (): void {
    InvokableBehavior::resetAllFakes();
});
```

#### Step 4: Adopt New Testing APIs (Optional)

Migrate from verbose testing patterns to the new fluent API. See [Testing Migration Patterns](#testing-migration-patterns) below for a complete pattern-by-pattern migration reference.

For full testing documentation, see the [Testing Overview](/testing/overview).

### Testing Migration Patterns

Migrating from pre-v5.1 testing patterns to v5.1+ best practices.

#### Pattern 1: `run()` → `runWithState()`

<!-- doctest-attr: ignore -->
```php
// ❌ BEFORE — no engine injection, raised events lost
$result = MyGuard::run($context);
MyAction::run($context, $event);

// ✅ AFTER — engine-identical parameter injection
$state = State::forTesting($context->toArray());
$result = MyGuard::runWithState($state);
$eventQueue = MyAction::runWithState($state, $event);
```

**Why migrate:** `run()` doesn't use the engine's `injectInvokableBehaviorParameters()`. This means:
- eventQueue is not passed (raised events are lost)
- Behavior arguments are not injected
- State/History are not available to the behavior

| Feature | `run()` | `runWithState()` |
|---------|---------|-------------------|
| Container resolution (DI) | Yes | Yes |
| Respects fakes | Yes | Yes |
| Engine parameter injection | No | Yes |
| eventQueue passed | No | Yes |
| Raised events accessible | No | Yes (returned) |
| Behavior arguments | No | Yes (`$arguments` param) |
| State/History injection | No | Yes (from State object) |

**When `run()` is still acceptable:** In tests where you're testing the fake/mock mechanism itself, not behavior logic.

#### Pattern 2: `new Behavior()` + `__invoke()` → `runWithState()`

<!-- doctest-attr: ignore -->
```php
// ❌ BEFORE — bypasses container, no DI, fakes ignored
$guard = new IsFraudDetectedGuard();
$guard->validateRequiredContext($context);
$result = $guard($context, $event);

// ✅ AFTER — container-resolved, DI works, fakes respected
$state = State::forTesting($context->toArray());
IsFraudDetectedGuard::validateRequiredContext($state->context);
$result = IsFraudDetectedGuard::runWithState($state, $event);
```

#### Pattern 3: `Machine::create()` → `Machine::test()`

For assertion-heavy tests, use the fluent TestMachine wrapper:

<!-- doctest-attr: ignore -->
```php
// ❌ BEFORE — verbose, manual assertions
$machine = TrafficLightsMachine::create();
$state = $machine->send(['type' => 'INCREASE']);
assertEquals(1, $state->context->get('count'));
assertTrue($state->matches('traffic_lights_machine.active'));

// ✅ AFTER — fluent, contextual failure messages
TrafficLightsMachine::test()
    ->send('INCREASE')
    ->assertContext('count', 1)
    ->assertState('active');
```

| Feature | `Machine::create()` | `Machine::test()` |
|---------|---------------------|---------------------|
| Fluent assertions | No | Yes (21 methods) |
| Failure messages | Generic | Contextual |
| Persistence control | Must modify definition | `->withoutPersistence()` |
| Behavior faking | Manual setup | `->faking([...])` |
| Path testing | Manual loop | `->assertPath([...])` |
| Chaining | send() returns State | All methods return self |

::: info Machine::create() is not deprecated
`Machine::create()` is the **production** API. Use it when you need the raw Machine instance or are testing persistence/restoration logic. `Machine::test()` is for assertion-heavy test scenarios.
:::

#### Pattern 4: `getGuard('name')` for class behaviors → `runWithState()`

<!-- doctest-attr: ignore -->
```php
// ❌ AVOID for class behaviors — unnecessary indirection
$guard = OrderMachine::getGuard('isValidOrderGuard');
$result = $guard($context);

// ✅ PREFERRED — direct, goes through container
$result = IsValidOrderGuard::runWithState(State::forTesting([...]));
```

::: tip getGuard() is still ideal for inline closures
`Machine::getGuard('name')` remains the **only way** to extract and test inline closure behaviors. No migration needed for inline behaviors.

```php no_run
// ✅ STILL CORRECT for inline closures
$guard = OrderMachine::getGuard('isPositiveGuard');  // fn($ctx) => $ctx->count > 0
```
:::

#### Decision Tree

```
Q: What are you testing?
│
├─ A single behavior class (guard/action/calculator)?
│  ├─ It's a class (extends GuardBehavior etc)?
│  │  └─ Use: BehaviorClass::runWithState(State::forTesting([...]))
│  └─ It's an inline closure (fn($ctx) => ...)?
│     └─ Use: Machine::getGuard('name') + injectInvokableBehaviorParameters()
│
├─ Machine state flow / transitions?
│  ├─ Need DB persistence?
│  │  └─ Use: Machine::test() (don't call withoutPersistence)
│  └─ No DB needed?
│     └─ Use: Machine::test()->withoutPersistence()->send()->assertState()
│
├─ Behavior orchestration (which sub-actions fire)?
│  └─ Use: Machine::test()->faking([...])->send()->assertBehaviorRan()
│
├─ Context validation (requiredContext)?
│  └─ Use: BehaviorClass::validateRequiredContext($context)
│
└─ Event validation?
   └─ Use: MyEvent::forTesting([...])->selfValidate()
```

::: tip Related
See [Isolated Testing](/testing/isolated-testing) for `runWithState()` details,
[TestMachine](/testing/test-machine) for the fluent wrapper API,
and [Fakeable Behaviors](/testing/fakeable-behaviors) for the faking system.
:::

---

## Upgrading to v5.0

v5.0 adds true parallel dispatch for parallel states with configurable region timeout.

### New Feature: Parallel Dispatch

Opt-in concurrent execution of parallel region entry actions via Laravel queue jobs. Existing parallel state machines continue to work unchanged — parallel dispatch is disabled by default.

### Enable Parallel Dispatch

Publish and update the config:

```php ignore
// config/machine.php
return [
    'parallel_dispatch' => [
        'enabled'        => env('MACHINE_PARALLEL_DISPATCH_ENABLED', false),
        'queue'          => env('MACHINE_PARALLEL_DISPATCH_QUEUE', null),
        'lock_timeout'   => env('MACHINE_PARALLEL_DISPATCH_LOCK_TIMEOUT', 30),
        'lock_ttl'       => env('MACHINE_PARALLEL_DISPATCH_LOCK_TTL', 60),
        'job_timeout'    => env('MACHINE_PARALLEL_DISPATCH_JOB_TIMEOUT', 300),
        'job_tries'      => env('MACHINE_PARALLEL_DISPATCH_JOB_TRIES', 3),
        'job_backoff'    => env('MACHINE_PARALLEL_DISPATCH_JOB_BACKOFF', 30),
        'region_timeout' => env('MACHINE_PARALLEL_DISPATCH_REGION_TIMEOUT', 0),
    ],
];
```

### Requirements

When parallel dispatch is enabled:

1. **Cache driver must support atomic locks** — Redis or database (not `array` or `file`)
2. **Queue worker must be running** — Region jobs are dispatched to the queue
3. **Entry actions must be idempotent** — Jobs may be retried on failure
4. **Parallel regions should write to different context keys** — Shared keys trigger a `PARALLEL_CONTEXT_CONFLICT` event (LWW applies)

### New Feature: Region Timeout

When `region_timeout` is set (seconds), a delayed check job fires after the configured duration. If the parallel state has not completed (any region still not final), it triggers `@fail` on the parallel state.

```php ignore
'parallel_dispatch' => [
    'region_timeout' => 120, // Trigger @fail after 2 minutes (0 = disabled)
],
```

### New Files

| File | Description |
|------|-------------|
| `src/Jobs/ParallelRegionJob.php` | Internal queue job for region entry actions |
| `src/Jobs/ParallelRegionTimeoutJob.php` | Delayed check job for stuck parallel state detection |
| `src/Locks/MachineLockManager.php` | Database-based lock management |
| `src/Support/ArrayUtils.php` | Shared recursive array merge/diff utilities |

### New Internal Events

v5.0 adds seven internal events for parallel dispatch observability:

| Event | Purpose |
|-------|---------|
| `PARALLEL_REGION_ENTER` | Region job completed and persisted context |
| `PARALLEL_REGION_GUARD_ABORT` | Under-lock guard discarded work (machine moved on) |
| `PARALLEL_CONTEXT_CONFLICT` | Sibling region overwrote a shared context key (LWW) |
| `PARALLEL_REGION_STALLED` | Region entry action completed without advancing (no raise) |
| `PARALLEL_REGION_TIMEOUT` | Parallel state did not complete within `region_timeout` seconds |
| `PARALLEL_DONE` | All regions reached final, `@done` fired |
| `PARALLEL_FAIL` | Region job failed after all retries |

All events are persisted as `MachineEvent` records — durable audit trail, not logs.

### Migration Steps

1. Update the package:
```bash
composer update tarfinlabs/event-machine:^5.0
```

2. Publish and run migrations (adds `machine_locks` table for parallel dispatch locking):
```bash
php artisan vendor:publish --tag=machine-migrations
php artisan migrate
```

3. Publish config if not already done:
```bash
php artisan vendor:publish --tag=machine-config
```

4. Add parallel dispatch keys to your `config/machine.php`
5. Set `MACHINE_PARALLEL_DISPATCH_ENABLED=true` in `.env` when ready
6. Ensure your cache and queue drivers are configured

For full details, see [Parallel Dispatch](/advanced/parallel-states/parallel-dispatch).

---

## Upgrading to v4.0

v4.0 adds parallel states support with full lifecycle management.

### Breaking Changes

- **Dropped Laravel 10 support** — requires Laravel 11+
- **Dropped PHP 8.2 support** — requires PHP 8.3+ (Pest v4 dependency)
- **Dropped Orchestra Testbench ^8.x** — requires ^9.0+

### New Features

- **Parallel States** — `type: 'parallel'` with multiple concurrent regions, `onDone` auto-transitions when all regions reach final states
- **Compound State `onDone`** — XState-compatible `onDone` transitions for compound states within parallel regions, with recursive chaining
- **Cross-Region Synchronization** — `@always` transitions with guards for cross-region state checking
- **DocTest Integration** — Documentation code blocks tested automatically via `testflowlabs/doctest`
- **Multi-value state support** — `matches()`, `matchesAll()`, `isInParallelState()` for parallel regions

### Migration Steps

1. Upgrade to PHP 8.3+ and Laravel 11+ first

2. Update the package:
```bash
composer require tarfinlabs/event-machine:^4.0
```

3. Review any custom `StateConfigValidator` usage — parallel state validation now uses `InvalidParallelStateDefinitionException`

---

## Upgrading to v3.x

### Requirements

- PHP 8.2+ (upgraded from 8.1)
- Laravel 10.x, 11.x, or 12.x

### Breaking Changes

#### 1. Behavior Parameter Injection

**Before (v2.x):**
```php
use Tarfinlabs\EventMachine\Behavior\ActionBehavior; // [!code hide]
class MyAction extends ActionBehavior
{
    public function __invoke($context, $event): void
    {
        // Parameters were positional
    }
}
```

**After (v3.x):**
```php
use Tarfinlabs\EventMachine\Behavior\ActionBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]
use Tarfinlabs\EventMachine\Behavior\EventBehavior; // [!code hide]
class MyAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): void
    {
        // Type-hinted parameters are injected
    }
}
```

Parameters are now injected based on type hints, not position. Ensure all behaviors use proper type hints.

#### 2. ContextManager Changes

**Before (v2.x):**
<!-- doctest-attr: ignore -->
```php
$context->data['key'] = 'value';
```

**After (v3.x):**
<!-- doctest-attr: ignore -->
```php
$context->set('key', 'value');
// or
$context->key = 'value';
```

Direct array access is deprecated. Use `get()`, `set()`, and magic methods.

#### 3. State Matching

**Before (v2.x):**
<!-- doctest-attr: ignore -->
```php
$machine->state->value === 'pending';
```

**After (v3.x):**
<!-- doctest-attr: ignore -->
```php
$machine->state->matches('pending');
```

The `matches()` method handles machine ID prefixing automatically.

#### 4. Event Class Registration

**Before (v2.x):**
<!-- doctest-attr: ignore -->
```php
'on' => [
    'SUBMIT' => [...],
],
'behavior' => [
    'events' => [
        'SUBMIT' => SubmitEvent::class,
    ],
],
```

**After (v3.x):**
<!-- doctest-attr: ignore -->
```php
// Option 1: Event class as key (auto-registered)
'on' => [
    SubmitEvent::class => [...],
],

// Option 2: Explicit registration still works
'on' => [
    'SUBMIT' => [...],
],
'behavior' => [
    'events' => [
        'SUBMIT' => SubmitEvent::class,
    ],
],
```

Using event classes as transition keys is now supported.

#### 5. Calculator Behavior

**New in v3.x:**
```php
use Tarfinlabs\EventMachine\Behavior\CalculatorBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]
class CalculateTotalCalculator extends CalculatorBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->total = $context->subtotal + $context->tax;
    }
}
```

Calculators are a new behavior type that runs before guards.

### Migration Steps

#### Step 1: Update Dependencies

```bash
composer require tarfinlabs/event-machine:^3.0
```

#### Step 2: Run Migrations

```bash
php artisan migrate
```

New columns may be added to the `machine_events` table.

#### Step 3: Update Behavior Type Hints

<!-- doctest-attr: ignore -->
```php
// Before
public function __invoke($context, $event): void

// After
public function __invoke(ContextManager $context, EventBehavior $event): void
```

#### Step 4: Update State Checks

<!-- doctest-attr: ignore -->
```php
// Before
if ($machine->state->value === 'order.pending') {

// After
if ($machine->state->matches('pending')) {
```

#### Step 5: Update Context Access

<!-- doctest-attr: ignore -->
```php
// Before
$machine->state->context->data['orderId']

// After
$machine->state->context->orderId
// or
$machine->state->context->get('orderId')
```

#### Step 6: Review Guard/Action Separation

Consider moving context modifications from guards to calculators:

<!-- doctest-attr: ignore -->
```php
// Before (v2.x) - guard modifying context
'guards' => [
    'validateAndCalculate' => function ($context) {
        $context->total = $context->subtotal * 1.1;  // Don't do this in guards
        return $context->total > 0;
    },
],

// After (v3.x) - separate concerns
'calculators' => [
    'calculateTotal' => function ($context) {
        $context->total = $context->subtotal * 1.1;
    },
],
'guards' => [
    'hasPositiveTotal' => function ($context) {
        return $context->total > 0;
    },
],
```

### New Features in v3.x

#### Calculators

Run before guards to modify context:

<!-- doctest-attr: ignore -->
```php
'on' => [
    'SUBMIT' => [
        'target' => 'processing',
        'calculators' => 'calculateTotal',
        'guards' => 'hasPositiveTotal',
        'actions' => 'processOrder',
    ],
],
```

#### Event Class Keys

Use event classes directly as transition keys:

<!-- doctest-attr: ignore -->
```php
use App\Events\SubmitEvent;

'on' => [
    SubmitEvent::class => [
        'target' => 'processing',
    ],
],
```

#### Improved Type Safety

Custom context classes with full validation:

```php
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]
class OrderContext extends ContextManager
{
    public function __construct(
        public string $orderId = '',
        public int $total = 0,
    ) {
        parent::__construct();
    }

    public static function rules(): array
    {
        return [
            'orderId' => ['required', 'string'],
            'total'   => ['integer', 'min:0'],
        ];
    }
}
```

#### Archive System

Event archival with compression:

```bash
php artisan machine:archive-events
```

---

## Upgrading to v2.x

### From v1.x

#### State Value Format

**Before (v1.x):**
<!-- doctest-attr: ignore -->
```php
$machine->state->value; // 'pending'
```

**After (v2.x):**
<!-- doctest-attr: ignore -->
```php
$machine->state->value; // ['machine.pending']
```

State values are now arrays containing the full path.

#### Machine Creation

**Before (v1.x):**
<!-- doctest-attr: ignore -->
```php
$machine = new OrderMachine();
$machine->start();
```

**After (v2.x):**
<!-- doctest-attr: ignore -->
```php
$machine = OrderMachine::create();
```

Use the static `create()` method.

#### Event Sending

**Before (v1.x):**
<!-- doctest-attr: ignore -->
```php
$machine->dispatch('SUBMIT', ['key' => 'value']);
```

**After (v2.x):**
<!-- doctest-attr: ignore -->
```php
$machine->send([
    'type' => 'SUBMIT',
    'payload' => ['key' => 'value'],
]);
```

Events use array format with `type` and `payload` keys.

