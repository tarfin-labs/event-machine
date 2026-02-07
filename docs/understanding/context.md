# Context

Context is the data that travels with your state machine. While states describe "where" your machine is, context describes the "what" - the accumulated data from events and computations.

## Quick Overview

```php ignore
MachineDefinition::define(
    config: [
        'initial' => 'idle',
        'context' => [
            'count' => 0,
            'items' => [],
            'total' => 0.0,
        ],
        'states' => [...],
    ],
);
```

## Reading Context

```php no_run
$state = $machine->state;

// Get a value
$count = $state->context->get('count');

// Check if key exists
$state->context->has('customer');

// Get all as array
$data = $state->context->toArray();
```

## Writing Context

Actions modify context during transitions:

```php
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\ContextManager;

class AddItemAction extends ActionBehavior
{
    public function __invoke(
        ContextManager $context,
        EventBehavior $event
    ): void {
        $items = $context->get('items', []);
        $items[] = $event->payload['item'];
        $context->set('items', $items);
    }
}
```

## Context in Guards

Guards read context to control transitions:

```php
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;
use Tarfinlabs\EventMachine\ContextManager;

class HasItemsGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context): bool
    {
        return count($context->get('items', [])) > 0;
    }
}
```

## Key Concepts

| Concept | Description |
|---------|-------------|
| **Initial Context** | Default values when machine starts |
| **ContextManager** | Class that holds and manages context data |
| **Custom Context** | Type-safe context with validation |
| **Persistence** | Context is saved with each event |

## Learn More

For complete context documentation including:

- Custom context classes with validation
- Context validation with Laravel Data attributes
- Required context in behaviors
- Magic property access
- Complete examples

See **[Working with Context](/building/working-with-context)**.
