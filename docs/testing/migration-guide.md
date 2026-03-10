# Testing Migration Guide

Migrating from pre-v5.1 testing patterns to v5.1+ best practices.

## Pattern 1: `run()` → `runWithState()`

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

## Pattern 2: `new Behavior()()` → `runWithState()`

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

## Pattern 3: `Machine::create()` → `Machine::test()`

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

## Pattern 4: `getGuard('name')` for class behaviors → `runWithState()`

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

## Decision Tree

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
