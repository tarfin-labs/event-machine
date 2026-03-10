# Upgrade Guide

## Upgrading to the Testability Release

### Breaking Change 1: Behavior Resolution via Container

Behaviors are now resolved through Laravel's service container (`App::make()`) instead of direct instantiation (`new $class()`). This enables constructor dependency injection.

**Before:**
```php
// MachineDefinition::getInvokableBehavior()
return new $behaviorDefinition($this->eventQueue);
```

**After:**
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

> **Note:** The `❌ BREAKS` scenario was already broken with `new $class($eventQueue)` — a Collection would be passed as the `$prefix` string parameter, causing a type mismatch. Container resolution **surfaces existing bugs** rather than creating new ones.

### Breaking Change 2: `InvokableBehavior::run()` Always Uses Container

**Before:**
```php
$instance = static::isFaked() ? App::make(static::class) : new static();
```

**After:**
```php
$instance = App::make(static::class);
```

**Who is affected:** Code that calls `::run()` and relies on `new static()` bypassing the container. In practice, no one — this method was only used in tests.

### Breaking Change 3: `Fakeable::fake()` Uses `App::bind()` with Closure

`Fakeable::fake()` now registers fakes via `App::bind($class, fn() => $mock)` instead of the previous dual storage approach. The closure always returns the same Mockery mock instance.

**Why `App::bind()` instead of `App::instance()`:** Laravel's `App::instance()` bindings are **ignored** when `App::make()` is called with explicit parameters (e.g., `App::make(MyGuard::class, ['eventQueue' => $queue])`). Since `getInvokableBehavior()` always passes `['eventQueue' => $this->eventQueue]`, `App::instance()` would silently bypass the fake.

**Cleanup change:** `resetFakes()` now uses `app()->offsetUnset($class)` instead of `App::forgetInstance($class)`, because `forgetInstance()` only removes instance bindings, not closure bindings. The public API is identical.

---

### New Testing Features

| Feature | Description |
|---------|-------------|
| `Machine::test()` | Livewire-style fluent test wrapper |
| `State::forTesting()` | Lightweight state factory for unit tests |
| `InvokableBehavior::runWithState()` | Isolated testing with engine-identical DI |
| `EventBehavior::forTesting()` | Test factory for event construction |
| Constructor DI | Behaviors can now inject service dependencies via `__construct()` |
| `Fakeable::spy()` | Permissive mock that records all calls silently |
| `Fakeable::allowToRun()` | Spy mode — allows and records `__invoke` calls |
| `Fakeable::assertRanWith()` | Assert `__invoke` was called with matching arguments |
| `Fakeable::assertRanTimes()` | Assert `__invoke` was called exactly N times |

See the [Testing Overview](/testing/overview) documentation for the complete guide.
