# Named Parameters for Behaviors

**Status:** Draft
**Date:** 2026-03-28

---

## Problem

Behaviors (guards, actions, calculators) that need configuration parameters use a string-based `:arg1,arg2` syntax:

```php
'guards' => 'isAmountInRangeGuard:100,10000'
```

The guard receives these as an untyped positional array:

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
- No IDE navigation to see what params a behavior accepts
- Comma in values is impossible (JSON, arrays)
- No default values
- No validation — missing or extra args silently ignored

---

## Solution

Array syntax with named parameters, resolved via the existing `__invoke` parameter injection system:

```php
// Config
'guards' => [IsAmountInRangeGuard::class, 'min' => 100, 'max' => 10000],
```

```php
// Guard
class IsAmountInRangeGuard extends GuardBehavior
{
    public function __invoke(ContextManager $ctx, int $min, int $max): bool
    {
        return $ctx->get('amount') >= $min
            && $ctx->get('amount') <= $max;
    }
}
```

Framework resolves `__invoke` parameters by type first, then by name:
1. `ContextManager $ctx` → known type → inject from framework
2. `int $min` → unknown type → match `'min'` from config params → inject `100`
3. `int $max` → unknown type → match `'max'` from config params → inject `10000`

Definition remains **pure data** (strings, arrays, primitives) — serializable, cacheable, XState-exportable.

---

## Syntax

### Single parameterized behavior

```php
// Class reference with named params
'guards' => [IsAmountInRangeGuard::class, 'min' => 100, 'max' => 10000],

// Inline key with named params
'guards' => ['isAmountInRangeGuard', 'min' => 100, 'max' => 10000],
```

### Parameterless behavior (unchanged)

```php
'guards' => HasBalanceGuard::class,
'guards' => 'hasBalanceGuard',
```

### Inline closure (unchanged)

```php
'guards' => fn (ContextManager $ctx): bool => $ctx->get('amount') > 100,
```

### Multiple behaviors

```php
// All parameterless
'guards' => [HasBalanceGuard::class, IsActiveGuard::class],

// Mixed — wrap parameterized ones in their own array
'guards' => [
    [IsAmountInRangeGuard::class, 'min' => 100, 'max' => 10000],
    HasBalanceGuard::class,
],
```

### Old syntax (deprecated)

```php
'guards' => 'isAmountInRangeGuard:100,10000'
```

Still works. Behaviors using `array $args` type-hint continue receiving positional string array.

---

## Disambiguation

When `guards`/`actions`/`calculators` receives an array, the framework must distinguish "list of behaviors" from "single parameterized behavior."

**Rule: if the array has any string keys, it is a single parameterized behavior. Otherwise, it is a list.**

| Array shape | Interpretation |
|-------------|----------------|
| `[Class::class, 'min' => 100]` | Single behavior with params (has string keys) |
| `[ClassA::class, ClassB::class]` | List of two behaviors (all numeric keys) |
| `[[ClassA::class, 'x' => 1], ClassB::class]` | List — first element is array (parameterized), second is plain |

Edge cases:
- `[Class::class]` — list with one element (numeric key only). Equivalent to just `Class::class`.
- `[]` — empty list, no behaviors. No-op.

Invalid formats (throw `InvalidBehaviorDefinitionException`):
- `['min' => 100, 'max' => 200]` — string keys but no class reference at position `[0]`.
- `[ClassA::class, ClassB::class, 'min' => 100]` — mixed list and params. Ambiguous: wrap the parameterized one in its own array instead.

---

## Applies To All Behavior Keys

```php
// Transition guards
'guards' => [IsAmountInRangeGuard::class, 'min' => 100, 'max' => 10000],

// Transition actions
'actions' => [SendNotificationAction::class, 'channel' => 'sms'],

// Transition calculators
'calculators' => [ApplyDiscountCalculator::class, 'rate' => 0.15],

// Entry / exit actions
'entry' => [LogTransitionAction::class, 'level' => 'info'],

// Full transition example
'SUBMIT' => [
    'target'  => 'processing',
    'guards'  => [IsAmountInRangeGuard::class, 'min' => 100, 'max' => 10000],
    'actions' => SendEmailAction::class,
],
```

---

## Error Handling

**Missing required param:** If `__invoke` declares `int $min` but config has no `'min'` key and there is no default value, the framework throws `MissingBehaviorParameterException`:

```
IsAmountInRangeGuard requires parameter 'min' (int) but it was not provided in the definition.
```

**Extra config params:** Params in config that don't match any `__invoke` parameter are silently ignored.

**Type mismatch:** PHP's native type coercion applies. `'min' => '100'` injected into `int $min` works (PHP coerces). `'min' => 'abc'` into `int $min` throws `TypeError`.

**Definition-time validation:** `machine:validate-config` should catch missing params, invalid formats, and type mismatches before runtime. Invocation-time errors are the fallback for configs that bypass static validation.

---

## Backward Compatibility

Old `:arg1,arg2` syntax is deprecated but continues to work:

```php
// Deprecated
'guards' => 'isAmountInRangeGuard:100,10000'

// Preferred
'guards' => [IsAmountInRangeGuard::class, 'min' => 100, 'max' => 10000],
```

Both syntaxes coexist. No removal timeline set — deprecation is documentation-only for now.

---

## Implementation

### 1. Parsing — extract class and config params from definition

Where behavior definitions are resolved (guards, actions, calculators, entry/exit), detect the new array format:

```php
// Input: [IsAmountInRangeGuard::class, 'min' => 100, 'max' => 10000]
// Detection: is_array($definition) && has string keys

$configParams = array_filter($definition, fn ($k) => is_string($k), ARRAY_FILTER_USE_KEY);
$class = $definition[0];  // first numeric element = class reference or inline key
```

### 2. Injection — extend `injectInvokableBehaviorParameters()`

Add `?array $configParams = null` parameter. After known-type matching, match remaining params by name:

```php
$value = match (true) {
    // Existing: framework-provided
    is_a($typeName, ContextManager::class, true) => $state->context,
    is_a($typeName, EventBehavior::class, true)  => $eventBehavior,
    $state instanceof $typeName                   => $state,
    is_a($state->history, $typeName)              => $state->history,

    // Existing: positional args (deprecated `:arg` path)
    $typeName === 'array' && $actionArguments !== null => $actionArguments,

    // New: named config params — match by parameter name
    $configParams !== null
        && array_key_exists($parameter->getName(), $configParams)
        => $configParams[$parameter->getName()],

    // Default value from __invoke signature
    $parameter->isDefaultValueAvailable() => $parameter->getDefaultValue(),

    default => null,
};
```

### 3. Affected resolution points

- `TransitionDefinition::resolveGuards()` — guard resolution
- `TransitionDefinition::resolveCalculators()` — calculator resolution
- `TransitionBranch::runActions()` — transition action resolution
- `MachineDefinition::runAction()` — entry/exit/listener action resolution

### 4. Testing/Faking

Faking system (`fakingAllGuards`, `fakingAllActions`, etc.) currently checks behavior definitions by string comparison. Needs to handle array definitions: extract class name from `$definition[0]` when `is_array($definition)`.

### 5. XState Export

Named params map to XState v5's parameterized action format:

```json
{
  "type": "IsAmountInRangeGuard",
  "params": { "min": 100, "max": 10000 }
}
```

---

## Benefits

| Aspect | Old (`:args`) | New (named array) |
|--------|--------------|-------------------|
| Type safety | All strings | Native PHP types |
| Named parameters | No (`$args[0]`) | Yes (`'min' => 100`) |
| Default values | Manual in behavior | PHP parameter defaults |
| Complex values | Impossible | Arrays, enums, any PHP value |
| Definition is data | Yes | Yes (preserved) |
| Serializable | Yes | Yes |
| XState export | Positional only | Named params in JSON |
| Error messages | Silent null | `MissingBehaviorParameterException` |
