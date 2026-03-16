# State Design

States represent conditions -- what the machine currently _is_. The most important test for a state name is the "is" test: "The order **is** ___". If it does not read naturally, it probably should not be a state.

## The Decision Rule

> Does the value change which _transitions_ are available? **State.**
> Does it just carry data forward? **Context.**

This single question prevents 90% of state-design mistakes. If a piece of information affects the set of outgoing transitions, it belongs in the state hierarchy. If it is merely data that actions or guards read, it belongs in context.

## Anti-Pattern: State Explosion

Three independent boolean dimensions produce eight combinations:

```php ignore
// Anti-pattern: 8 states for 3 booleans

'states' => [
    'processing_priority_insured'     => [],
    'processing_priority_uninsured'   => [],
    'processing_standard_insured'     => [],
    'processing_standard_uninsured'   => [],
    'completed_priority_insured'      => [],
    'completed_priority_uninsured'    => [],
    'completed_standard_insured'      => [],
    'completed_standard_uninsured'    => [],
],
```

Each new boolean doubles the state count. This is unmanageable.

**Fix:** Use context for the independent dimensions and keep only the lifecycle in states.

```php ignore
// Clean: 2 states + context flags

'context' => [
    'is_priority' => false,
    'is_insured'  => false,
],
'states' => [
    'processing' => [
        'on' => [
            'ORDER_COMPLETED' => [
                'target' => 'completed',
                'guards' => 'isInsuredGuard',    // reads context
            ],
        ],
    ],
    'completed' => ['type' => 'final'],
],
```

If the booleans genuinely create independent lifecycles (different events, different transitions), use parallel states instead.

## Anti-Pattern: Flag States

Encoding a context flag into the state name:

```php ignore
// Anti-pattern: same lifecycle, different flag

'processing_with_priority'    => [
    'on' => ['ORDER_COMPLETED' => 'completed'],
],
'processing_without_priority' => [
    'on' => ['ORDER_COMPLETED' => 'completed'],
],
```

Both states have identical transitions. The "priority" flag does not change the machine's structure.

**Fix:** One `processing` state with `is_priority` in context. Guards or actions read the flag when it matters.

```php ignore
'context' => [
    'is_priority' => false,
],
'states' => [
    'processing' => [
        'on' => [
            'ORDER_COMPLETED' => [
                'target'  => 'completed',
                'actions' => 'notifyCompletionAction',  // action checks is_priority
            ],
        ],
    ],
    'completed' => ['type' => 'final'],
],
```

## Anti-Pattern: Mirrored States

Creating parallel copies for different actors:

```php ignore
// Anti-pattern: duplicated structure

'approved_by_manager'  => ['on' => ['ORDER_COMPLETED' => 'completed']],
'approved_by_director' => ['on' => ['ORDER_COMPLETED' => 'completed']],
'approved_by_vp'       => ['on' => ['ORDER_COMPLETED' => 'completed']],
```

**Fix:** One `approved` state, store the approver in context.

```php ignore
'context' => [
    'approved_by' => null,
],
'states' => [
    'approved' => [
        'on' => ['ORDER_COMPLETED' => 'completed'],
    ],
    'completed' => ['type' => 'final'],
],
```

## Refactoring Recipe: Flat to Hierarchical

Consider a 12-state flat machine for an order workflow:

```php ignore
// Before: 12 flat states with duplicated CANCEL transitions

'states' => [
    'idle'                 => ['on' => ['ORDER_SUBMITTED' => 'submitted', 'ORDER_CANCELLED' => 'cancelled']],
    'submitted'            => ['on' => ['PAYMENT_RECEIVED' => 'awaiting_shipment', 'ORDER_CANCELLED' => 'cancelled']],
    'awaiting_shipment'    => ['on' => ['SHIPMENT_DISPATCHED' => 'shipped', 'ORDER_CANCELLED' => 'cancelled']],
    'shipped'              => ['on' => ['DELIVERY_CONFIRMED' => 'delivered']],
    'delivered'            => ['on' => ['ORDER_CLOSED' => 'completed']],
    // ... more states, each repeating ORDER_CANCELLED ...
    'cancelled' => ['type' => 'final'],
    'completed' => ['type' => 'final'],
],
```

Every cancellable state duplicates `ORDER_CANCELLED`. Refactor into a hierarchical machine:

```php ignore
// After: hierarchical, with root-level cancel

'states' => [
    'active' => [
        'initial' => 'idle',
        'states'  => [
            'idle'              => ['on' => ['ORDER_SUBMITTED' => 'submitted']],
            'submitted'         => ['on' => ['PAYMENT_RECEIVED' => 'awaiting_shipment']],
            'awaiting_shipment' => ['on' => ['SHIPMENT_DISPATCHED' => 'shipped']],
            'shipped'           => ['on' => ['DELIVERY_CONFIRMED' => 'delivered']],
            'delivered'         => ['on' => ['ORDER_CLOSED' => '#order_workflow.completed']],
        ],
        'on' => [
            'ORDER_CANCELLED' => '#order_workflow.cancelled',  // one handler for all
        ],
    ],
    'cancelled' => ['type' => 'final'],
    'completed' => ['type' => 'final'],
],
```

The `ORDER_CANCELLED` handler is defined once on the `active` parent. Event bubbling handles the rest.

## Guidelines

1. **Apply the "is" test.** Every state name must complete "The order is ___". Use adjectives (`idle`, `active`), past participles (`submitted`, `paid`), or present participles (`processing`, `validating`).

2. **State changes = transition changes.** If adding a new value does not add, remove, or alter any transitions, it belongs in context, not states.

3. **Prefer hierarchy over duplication.** Group states that share common transitions under a parent and define shared handlers once.

4. **Limit leaf states to 5-7 per parent.** If a parent has more than seven children, look for grouping opportunities.

## Related

- [Naming Conventions](/building/conventions) -- state naming rules
- [Hierarchical States](/advanced/hierarchical-states) -- parent-child structure
- [Context Design](./context-design) -- when to use context instead
- [Event Bubbling](./event-bubbling) -- how parent handlers work
