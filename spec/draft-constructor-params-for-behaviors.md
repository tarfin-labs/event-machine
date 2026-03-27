# Constructor Parameters for Behaviors

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

## Solution

Support pre-constructed behavior instances using PHP 8 constructor promotion and named arguments:

```php
'guards' => new IsAmountInRangeGuard(min: 100, max: 10000),
```

```php
class IsAmountInRangeGuard extends GuardBehavior
{
    public function __construct(            // ← Configuration (from machine definition)
        private readonly int $min,
        private readonly int $max = PHP_INT_MAX,
    ) {}

    public function __invoke(               // ← Runtime injection (from framework)
        ContextManager $ctx,
    ): bool {
        return $ctx->get('amount') >= $this->min
            && $ctx->get('amount') <= $this->max;
    }
}
```

**Separation of concerns:**
- `__construct` = static configuration (what the behavior is parameterized with)
- `__invoke` = runtime dependencies (what the framework injects: context, event, state)

---

## Syntax

```php
// Single parameterized guard — object instance
'guards' => new IsAmountInRangeGuard(min: 100, max: 10000),

// Multiple guards — mixed instances and class references
'guards' => [
    new IsAmountInRangeGuard(min: 100, max: 10000),
    HasBalanceGuard::class,
],

// Parameterless — unchanged
'guards' => HasBalanceGuard::class,

// Inline closure — unchanged
'guards' => fn (ContextManager $ctx): bool => $ctx->get('amount') > 100,
```

No ambiguity: `instanceof InvokableBehavior` = pre-constructed instance, `string` = class reference or inline key.

---

## Applies To All Behaviors

```php
// Actions
'actions' => new SendNotificationAction(channel: 'sms', template: 'payment_received'),

// Guards
'guards' => new IsAmountInRangeGuard(min: 100, max: 10000),

// Calculators
'calculators' => new ApplyDiscountCalculator(rate: 0.15),

// Entry/Exit
'entry' => new LogStateTransitionAction(level: 'info'),

// Transition actions
'SUBMIT' => [
    'target'  => 'processing',
    'actions' => new ValidateAndStoreAction(strict: true),
],
```

---

## Backward Compatibility

The old `:arg1,arg2` syntax continues to work but is deprecated:

```php
// Deprecated — still works, will emit deprecation notice in v10
'guards' => 'isAmountInRangeGuard:100,10000'

// Preferred
'guards' => new IsAmountInRangeGuard(min: 100, max: 10000),
```

### Migration path

1. **v9**: Both syntaxes work. Old syntax emits no warning (soft deprecation — documented only).
2. **v10**: Old syntax emits runtime deprecation notice.
3. **v11**: Old syntax removed.

---

## Implementation

The behavior resolution pipeline needs one addition: if the definition is already an `InvokableBehavior` instance, use it directly instead of resolving from the behavior registry.

```php
// In getInvokableBehavior() or equivalent:
if ($behaviorDefinition instanceof InvokableBehavior) {
    return $behaviorDefinition;  // Already constructed
}

// Existing resolution: string key → lookup in behavior array → instantiate
```

### Affected resolution points

- `TransitionDefinition`: guard, action, calculator resolution
- `MachineDefinition::runAction()`: action resolution
- `StateDefinition`: entry/exit action resolution
- `MachineDefinition`: listener resolution

### Testing/Faking

`fakingAllGuards()`, `fakingAllActions()` etc. intercept by class name. Pre-constructed instances should be interceptable via `get_class($instance)`.

### XState Export

Pre-constructed instances export as `{ "type": "ClassName", "params": { "min": 100, "max": 10000 } }` — matching XState v5's parameterized action format.

---

## Benefits

| Aspect | Old (`:args`) | New (`new Class(...)`) |
|--------|--------------|----------------------|
| Type safety | All strings | Native PHP types |
| IDE autocomplete | None | Full constructor params |
| Named parameters | No (`$args[0]`) | Yes (`min: 100`) |
| Default values | Manual | PHP defaults |
| Validation | Runtime only | Constructor enforced |
| Complex values | Impossible | Arrays, objects, enums |
| Responsibility | `__invoke` does both config + runtime | Constructor = config, `__invoke` = runtime |
