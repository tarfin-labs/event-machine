# Event Bubbling

When EventMachine receives an event, it does not broadcast it to every state. Instead, it walks from the **active leaf state** upward through its parent chain until it finds a handler. This is called _event bubbling_ and it is one of the most important behaviours to understand for correct machine design.

## How It Works

`findTransitionDefinition()` starts at the current atomic (leaf) state and checks its `on` map. If the event type is not found, it moves to the parent state and checks again, repeating until it reaches the root. The first match wins.

```
leaf state  -->  parent state  -->  ...  -->  root state
  (check)          (check)                      (check)
```

If no handler is found at any level, a `NoTransitionDefinitionFoundException` is thrown.

## Example: Root-Level Cancel

A common pattern is handling a "global" event at the root level so it works from every child state.

```php ignore
'id'      => 'order_workflow',
'initial' => 'submitted',
'states'  => [
    'submitted' => [
        'on' => ['PAYMENT_RECEIVED' => 'processing'],
    ],
    'processing' => [
        'initial' => 'validating',
        'states'  => [
            'validating' => [
                'on' => ['VALIDATION_PASSED' => 'fulfilling'],
            ],
            'fulfilling' => [
                'on' => ['SHIPMENT_DISPATCHED' => 'shipped'],
            ],
        ],
    ],
    'shipped'   => [],
    'cancelled' => ['type' => 'final'],
],
// Root-level handler: works from ANY non-final state
'on' => [
    'ORDER_CANCELLED' => 'cancelled',
],
```

Whether the machine is in `submitted`, `validating`, or `fulfilling`, sending `ORDER_CANCELLED` will always resolve at the root and transition to `cancelled`. There is no need to duplicate the handler on every child.

## Anti-Pattern: Unintended Parent Catch

Bubbling can surprise you when a parent accidentally handles an event that a child was supposed to handle.

```php ignore
// Anti-pattern: parent catches RETRY before child sees it

'processing' => [
    'initial' => 'attempting',
    'on' => [
        'RETRY' => 'attempting',           // Parent handler
    ],
    'states' => [
        'attempting' => [
            'on' => [
                'RETRY' => [               // Never reached!
                    'target'  => 'attempting',
                    'actions' => 'logRetryAction',
                ],
            ],
        ],
    ],
],
```

In this example, the child defines `RETRY` with an extra action, but the parent also defines `RETRY`. Because the leaf state _does_ have a handler, the leaf handler wins and the parent one is never reached. However, if the code were reversed -- child lacks the handler, parent has it -- the parent would catch it, potentially skipping actions the developer expected to run at the child level.

**Fix:** Be explicit about which level owns each event. If the parent and child both need to react to the same event, consider using different event types or restructuring the hierarchy.

## Anti-Pattern: Over-Relying on Bubbling

When every event is handled at the root, the machine becomes a flat switch statement with extra indentation.

```php ignore
// Anti-pattern: root handles everything

'states' => [
    'idle'       => [],
    'processing' => [],
    'completed'  => ['type' => 'final'],
],
'on' => [
    'ORDER_SUBMITTED'      => 'processing',
    'PAYMENT_RECEIVED'     => 'completed',
    'ORDER_CANCELLED'      => 'completed',
],
```

This defeats the purpose of hierarchical states. The events can fire in any order from any state because they all resolve at the root -- a dangerous loss of structure.

**Fix:** Place handlers on the states where they make sense. `ORDER_SUBMITTED` belongs on `idle`, `PAYMENT_RECEIVED` belongs on `processing`. Reserve root-level handlers for truly global events like `ORDER_CANCELLED` or `FORCE_RESET`.

## Guidelines

1. **Leaf states own their events.** Put the handler on the state where the event is meaningful. If `PAYMENT_RECEIVED` only makes sense during `awaiting_payment`, define it there.

2. **Root-level `on` is for global events.** Events like `CANCEL`, `RESET`, or `FORCE_CLOSE` that must work from any state are good candidates for root-level handling.

3. **Avoid duplicate handlers at parent and child.** If both levels define the same event type, the leaf wins. This is correct behaviour, but it can confuse maintainers. Prefer using distinct event types when parent and child need separate reactions.

4. **Document intent.** When you place a handler at a parent level, add a comment explaining that it is intentionally global. Future readers (and future you) will thank you.

## Bubbling in Parallel States

In parallel states, each region is checked independently. If a region's leaf does not handle the event, bubbling walks up through the region's ancestors as usual. The `findTransitionDefinitionOrNull()` variant is used so that regions without a handler simply ignore the event rather than throwing an exception.

This means one event can cause transitions in multiple regions simultaneously -- each region independently resolves the event via its own bubbling chain.

## Related

- [Hierarchical States](/advanced/hierarchical-states) -- parent-child state structure
- [Parallel States](/advanced/parallel-states/) -- multi-region event handling
- [Event Design](./event-design) -- naming events for clarity
