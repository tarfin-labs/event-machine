# Upgrading Guide

Guide for upgrading between EventMachine versions.

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

Migrate from verbose testing patterns to the new fluent API. See the [Testing Migration Guide](/testing/migration-guide) for a complete pattern-by-pattern migration reference.

For full testing documentation, see the [Testing Overview](/testing/overview).

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
        #[Required]
        public string $orderId,

        #[Min(0)]
        public int $total = 0,
    ) {
        parent::__construct();
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

---

## Version Compatibility

| EventMachine | PHP | Laravel |
|--------------|-----|---------|
| 6.x | 8.3+ | 11.x, 12.x |
| 5.x | 8.3+ | 11.x, 12.x |
| 4.x | 8.3+ | 11.x, 12.x |
| 3.x | 8.2+ | 10.x, 11.x, 12.x |
| 2.x | 8.1+ | 9.x, 10.x |
| 1.x | 8.0+ | 8.x, 9.x |

---

## Getting Help

If you encounter issues during upgrade:

1. Check the [GitHub Issues](https://github.com/tarfinlabs/event-machine/issues)
2. Review the [Changelog](https://github.com/tarfinlabs/event-machine/blob/main/CHANGELOG.md)
3. Open a new issue with your upgrade scenario

## Related

- [Installation](/getting-started/installation) - Fresh installation guide
- [Your First Machine](/getting-started/your-first-machine) - Getting started tutorial
