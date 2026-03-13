# sendTo & sendToParent

## `sendTo()` — Send Events to Any Machine

Send an event to another machine instance by class and root event ID:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class NotifyTargetAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        // Sync: restores target machine and sends event immediately
        $this->sendTo(
            machineClass: TargetMachine::class,
            rootEventId: $context->get('target_machine_id'),
            event: ['type' => 'NOTIFICATION', 'payload' => ['message' => 'done']],
        );

        // Async: dispatches SendToMachineJob on queue
        $this->sendTo(
            machineClass: TargetMachine::class,
            rootEventId: $context->get('target_machine_id'),
            event: ['type' => 'NOTIFICATION'],
            async: true,
        );
    }
}
```

## `sendToParent()` — Child → Parent Communication

The primary use case for cross-machine messaging. A child reports progress or sends data to its parent:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class ReportProgressAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $this->sendToParent($context, [
            'type'    => 'CHILD_PROGRESS',
            'payload' => [
                'percent'   => 50,
                'processed' => 5,
                'total'     => 10,
            ],
        ]);
    }
}
```

`sendToParent()` uses `$context->parentMachineId()` and `$context->parentMachineClass()` internally. Throws `RuntimeException` if the machine was not invoked by a parent.

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$context` | `ContextManager` | — | The child's context (used to resolve parent identity) |
| `$event` | `EventBehavior\|array` | — | The event to send to the parent |
| `$async` | `bool` | `false` | When `true`, dispatches `SendToMachineJob` instead of sending immediately |

## Progress Reporting Pattern

Combine `sendToParent()` with the parent's `on` map for bidirectional communication:

<!-- doctest-attr: ignore -->
```php
// Parent config — receives progress updates while child runs
'batch_processing' => [
    'machine' => BatchProcessMachine::class,
    'with'    => ['items'],
    'queue'   => 'batch',
    '@done'   => 'completed',
    '@fail'   => 'failed',
    'on' => [
        'CHILD_PROGRESS' => ['actions' => 'updateProgressAction'],
        'CANCEL'         => 'cancelled',
    ],
],
```

Progress events arrive as regular events on the parent's `on` map. The parent stays in the same state — it just runs actions.

## raise() vs sendTo() vs sendToParent()

| Aspect | `raise()` | `sendTo()` | `sendToParent()` |
|--------|-----------|------------|------------------|
| Target | Same machine | Any machine | Parent machine |
| Mechanism | In-memory event queue | `send()` or job dispatch | Same as `sendTo()` |
| Timing | Same transition cycle | Immediate or queued | Immediate or queued |
| Data flow | Same context | Isolated contexts | Isolated contexts |
| Use case | Internal events | Peer messaging | Progress reporting |
