# Upgrading Guide

Guide for upgrading between EventMachine versions.

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
