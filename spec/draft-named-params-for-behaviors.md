# Named Parameters for Behaviors

**Status:** Draft
**Date:** 2026-03-28

---

## Problem

Behaviors (guards, actions, calculators) that need configuration parameters use a string-based `:arg1,arg2` syntax:

```php
'guards' => 'isAmountInRangeGuard:100,10000'
```

The guard receives these as an untyped array:

```php
class IsAmountInRangeGuard extends GuardBehavior
{
    public function __invoke(ContextManager $ctx, array $args): bool
    {
        return $ctx->get('amount') >= (int) $args[0]   // string → manual cast
            && $ctx->get('amount') <= (int) $args[1];   // positional, unnamed
    }
}
```

**Problems:**
- All arguments are strings — manual casting required
- Positional only — `$args[0]` says nothing about what it is
- No IDE autocomplete or navigation
- Comma in values is impossible (JSON, arrays)
- No default values
- No validation at definition time

---

## Design Principle

Machine definitions have always been **pure data** — strings, arrays, class references. No `new`, no object instantiation. This property enables:
- XState JSON export (`machine:xstate`)
- Machine definition caching (`machine:cache`)
- Static validation (`machine:validate-config`)
- Config-driven architecture (definitions from DB, file, API)

The solution must preserve this property. **No `new Class()` in definitions.**

---

## Solution

Named parameter array syntax — extends the existing parameter injection system:

```php
// Config — pure data (array with class reference + named params)
'guards' => [IsAmountInRangeGuard::class, 'min' => 100, 'max' => 10000],
```

```php
// Guard — framework injects both runtime deps AND config params into __invoke
class IsAmountInRangeGuard extends GuardBehavior
{
    public function __invoke(ContextManager $ctx, int $min, int $max): bool
    {
        return $ctx->get('amount') >= $min
            && $ctx->get('amount') <= $max;
    }
}
```

Framework resolves `__invoke` parameters:
1. `ContextManager $ctx` → type match → inject from framework
2. `int $min` → no type match → look up `'min'` in config params → inject `100`
3. `int $max` → no type match → look up `'max'` in config params → inject `10000`

This is a natural extension of the existing DI system. The `__invoke` signature already mixes framework-provided and config-provided params (`array $args`). This just makes config params named and typed.

---

## Syntax

### Single parameterized behavior

```php
// Named params (new — preferred)
'guards' => [IsAmountInRangeGuard::class, 'min' => 100, 'max' => 10000],

// String args (old — deprecated)
'guards' => 'isAmountInRangeGuard:100,10000',

// Parameterless (unchanged)
'guards' => HasBalanceGuard::class,

// Inline closure (unchanged)
'guards' => fn (ContextManager $ctx): bool => $ctx->get('amount') > 100,
```

### Multiple behaviors

```php
// Multiple — each element is its own definition
'guards' => [
    [IsAmountInRangeGuard::class, 'min' => 100, 'max' => 10000],
    HasBalanceGuard::class,
],
```

### Disambiguation rule

| First element | String keys present | Interpretation |
|---------------|-------------------|----------------|
| `string` | No | List of behavior references |
| `string` | Yes | Single parameterized behavior |
| `array` | — | List of behaviors (each can be parameterized) |
| `Closure` | — | Single inline behavior |

```php
// List of guards (all numeric keys, all strings)
'guards' => [IsAmountAboveGuard::class, HasBalanceGuard::class],

// Single parameterized guard (has string keys)
'guards' => [IsAmountInRangeGuard::class, 'min' => 100, 'max' => 10000],

// Mixed list (first element is array)
'guards' => [
    [IsAmountInRangeGuard::class, 'min' => 100, 'max' => 10000],
    HasBalanceGuard::class,
    'isActiveGuard',
],
```

---

## Applies To All Behaviors

```php
// Actions
'actions' => [SendNotificationAction::class, 'channel' => 'sms'],

// Guards
'guards' => [IsAmountInRangeGuard::class, 'min' => 100, 'max' => 10000],

// Calculators
'calculators' => [ApplyDiscountCalculator::class, 'rate' => 0.15],

// Entry/Exit
'entry' => [LogStateTransitionAction::class, 'level' => 'info'],

// Transition actions
'SUBMIT' => [
    'target'  => 'processing',
    'actions' => [ValidateAndStoreAction::class, 'strict' => true],
],
```

---

## Backward Compatibility

The old `:arg1,arg2` syntax continues to work but is deprecated:

```php
// Deprecated — still works
'guards' => 'isAmountInRangeGuard:100,10000'

// Preferred
'guards' => [IsAmountInRangeGuard::class, 'min' => 100, 'max' => 10000],
```

Behaviors that use `array $args` type-hint continue receiving the old positional array. New named params are injected by matching `__invoke` parameter names — no `array $args` needed.

### Migration path

1. **v9**: Both syntaxes work. Old syntax documented as deprecated (no runtime warning).
2. **v10**: Old syntax emits runtime deprecation notice.
3. **v11**: Old syntax removed.

---

## Implementation

Extend `InvokableBehavior::injectInvokableBehaviorParameters()`:

```php
// Current: known types → framework, `array` → positional args
// New: known types → framework, named scalars → match from config params

foreach ($reflectionParams as $parameter) {
    $value = match (true) {
        // Existing: framework-provided (ContextManager, EventBehavior, State, etc.)
        is_a($typeName, ContextManager::class, true) => $state->context,
        is_a($typeName, EventBehavior::class, true)  => $eventBehavior,
        $state instanceof $typeName                   => $state,

        // Existing: positional args (deprecated path)
        $typeName === 'array'                         => $actionArguments,

        // New: named config params
        $configParams !== null
            && array_key_exists($parameter->getName(), $configParams)
                                                      => $configParams[$parameter->getName()],

        // Default value
        $parameter->isDefaultValueAvailable()         => $parameter->getDefaultValue(),

        default                                       => null,
    };
}
```

### Parsing: extract config params from definition

```php
// [IsAmountInRangeGuard::class, 'min' => 100, 'max' => 10000]
// → class = IsAmountInRangeGuard::class
// → configParams = ['min' => 100, 'max' => 10000]

$class = array_shift($definition);  // first element = class reference
$configParams = $definition;         // rest = named params (string keys only)
```

### Affected resolution points

- `TransitionDefinition`: guard, action, calculator resolution
- `MachineDefinition::runAction()`: action resolution
- `StateDefinition`: entry/exit action resolution

### Testing/Faking

No change needed. Behaviors are still resolved by class name. Config params don't affect faking — faked behaviors skip `__invoke` entirely.

### XState Export

Named params export naturally:

```json
{
  "actions": {
    "type": "IsAmountInRangeGuard",
    "params": { "min": 100, "max": 10000 }
  }
}
```

This matches XState v5's parameterized action format.

---

## Benefits

| Aspect | Old (`:args`) | New (named array) |
|--------|--------------|-------------------|
| Type safety | All strings | Native PHP types |
| Named parameters | No (`$args[0]`) | Yes (`'min' => 100`) |
| Default values | Manual | PHP parameter defaults |
| Complex values | Impossible | Arrays, enums, any PHP value |
| Definition is data | Yes | Yes (preserved) |
| Serializable | Yes | Yes |
| XState export | Limited | Full (with params) |
| IDE autocomplete | None | Class reference navigable |
