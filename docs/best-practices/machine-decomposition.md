# Machine Decomposition

A single machine can model an entire domain -- but that does not mean it should. Knowing when to split a machine into parent and children, and when to keep things together, is a key design skill.

## When to Split

Extract a child machine when:

- **Own lifecycle.** The sub-flow has its own start, end, and failure modes independent of the parent. A payment flow can succeed, fail, or time out regardless of what the order is doing.

- **Reusable.** The same flow appears in multiple parent machines. A `ValidationMachine` used by both `OrderWorkflowMachine` and `ReturnWorkflowMachine` should be its own machine.

- **Complex enough for own tests.** If the sub-flow has 5+ states with branching logic, it deserves isolated test coverage.

- **Independent failure.** The sub-flow can fail and retry without affecting the parent's state. Payment retries should not force the order back to "submitted".

## When to Keep Together

Keep states in the same machine when:

- **Simple linear flow.** A 3-state progression (submitted -> processing -> completed) does not need decomposition.

- **Shared context.** The states all read and write the same context keys. Splitting would require passing everything via `with` and reporting everything via `@done` payload.

- **No independent failure.** A failure in one part always means a failure in the whole flow.

- **Tight coupling.** If you find yourself sending 5+ events between parent and child on every transition, they are one machine pretending to be two.

## Anti-Pattern: Mega-Machine

```php ignore
// Anti-pattern: 50+ states in a single machine

'states' => [
    'idle'                          => [...],
    'validating_customer'           => [...],
    'validating_address'            => [...],
    'validating_payment_method'     => [...],
    'calculating_tax'               => [...],
    'calculating_shipping'          => [...],
    'calculating_discount'          => [...],
    'awaiting_payment'              => [...],
    'processing_payment'            => [...],
    'payment_retrying'              => [...],
    'payment_failed'                => [...],
    'reserving_inventory'           => [...],
    'awaiting_shipment'             => [...],
    'generating_label'              => [...],
    'dispatching'                   => [...],
    'in_transit'                    => [...],
    'delivered'                     => [...],
    // ... 30 more states ...
],
```

A machine this size is impossible to visualise, test comprehensively, or reason about. Changes in the payment flow can accidentally affect shipping logic.

**Fix:** Identify sub-flows and extract them as child machines.

```php ignore
// Clean: parent orchestrates, children specialise

'states' => [
    'validating' => [
        'machine' => ValidationMachine::class,
        'with'    => ['order_id'],
        '@done'   => 'awaiting_payment',
        '@fail'   => 'validation_failed',
    ],
    'awaiting_payment' => [
        'on' => ['PAYMENT_RECEIVED' => 'processing_payment'],
    ],
    'processing_payment' => [
        'machine' => PaymentMachine::class,
        'with'    => ['order_id', 'order_total'],
        '@done'   => 'shipping',
        '@fail'   => 'payment_failed',
    ],
    'shipping' => [
        'machine' => ShippingMachine::class,
        'with'    => ['order_id'],
        '@done'   => 'completed',
        '@fail'   => 'shipping_failed',
    ],
    'completed'         => ['type' => 'final'],
    'validation_failed' => ['type' => 'final'],
    'payment_failed'    => ['type' => 'final'],
    'shipping_failed'   => ['type' => 'final'],
],
```

The parent reads like a story: validate, then pay, then ship. Each child is independently testable and reusable.

## Anti-Pattern: Too-Tiny Machines

```php ignore
// Anti-pattern: machine with 2 states

// TaxCalculationMachine
'states' => [
    'calculating' => ['on' => ['@always' => 'calculated']],
    'calculated'  => ['type' => 'final'],
],
```

A machine with one or two states that always transitions immediately adds overhead (database records, event persistence, delegation plumbing) without value.

**Fix:** A calculator or an action in the parent machine.

```php ignore
// Just use a calculator on the parent's transition

'on' => [
    'ORDER_CONFIRMED' => [
        'target'      => 'processing',
        'calculators' => 'taxAmountCalculator',
    ],
],
```

## Anti-Pattern: Excessive Cross-Machine Messaging

```php ignore
// Anti-pattern: parent and child constantly messaging

// Parent: sends START, CONFIGURE, VALIDATE, APPROVE, FINALIZE to child
// Child: sends STARTED, CONFIGURED, VALIDATED, APPROVED, FINALIZED to parent

// If every parent transition triggers a child message and vice versa,
// these are really one machine with extra serialisation overhead.
```

**Fix:** If two machines cannot operate independently -- if every state change in one requires a corresponding change in the other -- merge them. Use hierarchical states instead of delegation.

## Sync vs Async Decision

| Factor | Sync (`machine` key) | Async (`job` key) |
|--------|----------------------|-------------------|
| Duration | Under 1 second | Seconds to minutes |
| External input | None | Waits for webhook, API, human |
| Failure recovery | Immediate exception | Queue retry policies |
| Testing | `Machine::fake()` | `Machine::fake()` + queue assertions |

Generally: if the child completes within a single HTTP request without waiting for external input, use sync delegation. Otherwise, use async.

## Parent Orchestrates, Child Specialises

The parent machine's job is to decide _what_ to do next. The child machine's job is to decide _how_ to do it.

```php no_run
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

// Parent: orchestration (what)
class OrderWorkflowMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'order_workflow',
                'initial' => 'processing_payment',
                'context' => ['order_id' => null, 'order_total' => 0],
                'states'  => [
                    'processing_payment' => [
                        'machine' => PaymentMachine::class,
                        'with'    => ['order_id', 'order_total'],
                        '@done'   => 'shipping',
                        '@fail'   => 'payment_failed',
                    ],
                    'shipping' => [
                        'machine' => ShippingMachine::class,
                        'with'    => ['order_id'],
                        '@done'   => 'completed',
                        '@fail'   => 'shipping_failed',
                    ],
                    'completed'      => ['type' => 'final'],
                    'payment_failed' => ['type' => 'final'],
                    'shipping_failed' => ['type' => 'final'],
                ],
            ],
        );
    }
}
```

The parent does not know how payment processing works. It only knows that it starts, succeeds, or fails.

## Guidelines

1. **Own lifecycle = own machine.** If the sub-flow has independent start, success, and failure paths, extract it.

2. **Aim for 5-15 states per machine.** Fewer suggests the machine is too granular. More suggests it needs decomposition.

3. **Minimize cross-machine data.** Pass only the IDs and values the child needs via `with`. Return results via `@done` payload.

4. **Test children in isolation first.** Verify the child machine works correctly before integrating with the parent.

5. **Use `Machine::fake()` in parent tests.** Short-circuit child delegation to test the parent's orchestration logic without running children.

## Related

- [Machine Delegation](/advanced/machine-delegation) -- delegation mechanics
- [Async Delegation](/advanced/async-delegation) -- `job` key for async children
- [Delegation Data Flow](/advanced/delegation-data-flow) -- `with` and `@done` payload
- [Delegation Testing](/testing/delegation-testing) -- testing with `Machine::fake()`
