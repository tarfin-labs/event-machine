# Cross-Machine Messaging

EventMachine provides five messaging methods for communication between and within machines.

## `sendTo()` — Sync Send to Any Machine

Restores the target machine and sends the event immediately (blocking):

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class NotifyTargetAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $this->sendTo(
            machineClass: TargetMachine::class,
            rootEventId: $context->get('target_machine_id'),
            event: ['type' => 'NOTIFICATION', 'payload' => ['message' => 'done']],
        );
    }
}
```

## `dispatchTo()` — Async Send to Any Machine

Dispatches a `SendToMachineJob` to deliver the event via queue (non-blocking, fire-and-forget):

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class NotifyTargetAsyncAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $this->dispatchTo(
            machineClass: TargetMachine::class,
            rootEventId: $context->get('target_machine_id'),
            event: ['type' => 'NOTIFICATION'],
        );
    }
}
```

## `sendToParent()` — Sync Send to Parent

Sends an event synchronously to the parent machine that invoked this child:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class ReportCompleteAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $this->sendToParent($context, [
            'type'    => 'CHILD_COMPLETE',
            'payload' => ['status' => 'success'],
        ]);
    }
}
```

## `dispatchToParent()` — Async Send to Parent

Dispatches a `SendToMachineJob` to deliver the event to the parent via queue:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class ReportProgressAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $this->dispatchToParent($context, [
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

Both `sendToParent()` and `dispatchToParent()` use `$context->parentMachineId()` and `$context->parentMachineClass()` internally. They throw `RuntimeException` if the machine was not invoked by a parent.

## Progress Reporting Pattern

Combine `dispatchToParent()` with the parent's `on` map for bidirectional communication:

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

## Messaging Methods Comparison

| Method | Target | Mechanism | Timing | Data Flow | Use Case |
|--------|--------|-----------|--------|-----------|----------|
| `raise()` | Same machine | In-memory event queue | Same transition cycle | Same context | Internal events, auto-triggers |
| `sendTo()` | Any machine | Sync restore + send | Immediate, blocking | Isolated contexts | Instant notification, sync coordination |
| `dispatchTo()` | Any machine | `SendToMachineJob` via queue | When dequeued | Isolated contexts | Async notification, fire-and-forget |
| `sendToParent()` | Parent machine | Sync restore + send | Immediate, blocking | Isolated contexts | Sync progress reporting |
| `dispatchToParent()` | Parent machine | `SendToMachineJob` via queue | When dequeued | Isolated contexts | Async progress, heavy payloads |

## Which Method Should I Use?

1. **Sending to the same machine?** → `raise()`
2. **Sending to another machine?** → `sendTo()` or `dispatchTo()`
3. **Sending to the parent?** → `sendToParent()` or `dispatchToParent()`
4. **Must be processed immediately?** → sync (`sendTo` / `sendToParent`)
5. **Can be processed later?** → async (`dispatchTo` / `dispatchToParent`)

## Testing Cross-Machine Communication

<!-- doctest-attr: ignore -->
```php
// Synchronous: record and assert
ChildMachine::test()
    ->recordingCommunication()
    ->send('COMPLETE')
    ->assertSentTo(ParentMachine::class, 'CHILD_COMPLETED');

// Asynchronous: use Queue::fake
Queue::fake();
OrderMachine::test()
    ->send('NOTIFY')
    ->assertDispatchedTo(AuditMachine::class, 'ORDER_COMPLETED');
```

::: tip Full Testing Guide
For comprehensive cross-machine communication testing patterns, see [Cross-Machine Communication Assertions](/testing/test-machine#cross-machine-communication-assertions).
:::
