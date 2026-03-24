# Context

Context is the data that travels with your state machine. While states describe "where" your machine is, context describes the "what" - the accumulated data from events and computations.

## Quick Overview

Context is defined as a typed class extending `ContextManager`:

```php ignore
use Tarfinlabs\EventMachine\ContextManager;

class OrderContext extends ContextManager
{
    public function __construct(
        public int $count = 0,
        public array $items = [],
        public float $total = 0.0,
    ) {}
}
```

Reference it in your machine configuration:

```php ignore
MachineDefinition::define(
    config: [
        'initial' => 'idle',
        'context' => OrderContext::class,
        'states' => [...],
    ],
);
```

## Reading Context

```php no_run
$state = $machine->state;

// Direct property access (type-safe)
$count = $state->context->count;
$items = $state->context->items;

// Get all as array
$data = $state->context->toArray();
```

## Writing Context

Actions modify context during transitions:

```php no_run
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class AddItemAction extends ActionBehavior
{
    public function __invoke(
        OrderContext $context,
        EventBehavior $event
    ): void {
        $context->items = [...$context->items, $event->payload()['item']];
    }
}
```

## Context in Guards

Guards read context to control transitions:

```php no_run
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;

class HasItemsGuard extends GuardBehavior
{
    public function __invoke(OrderContext $context): bool
    {
        return count($context->items) > 0;
    }
}
```

## Key Concepts

| Concept | Description |
|---------|-------------|
| **ContextManager** | Base class for typed context with validation |
| **TypedData** | Shared base providing reflection-based from()/toArray() and cast resolution |
| **Persistence** | Context is saved with each event |

## Learn More

For complete context documentation including:

- Custom context classes with validation
- Context validation with `rules()` method
- Required context in behaviors
- Magic property access
- Complete examples

See **[Working with Context](/building/working-with-context)**.

::: tip Testing
For context assertion methods (`assertContext`, `assertContextHas`), see [Testing Overview](/testing/overview).
:::
