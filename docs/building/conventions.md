# Naming Conventions

Consistent naming makes your state machines easier to read, maintain, and debug. This guide covers the recommended naming conventions for every part of an EventMachine definition.

## Quick Reference

| Element | Style | Pattern | Example |
|---------|-------|---------|---------|
| Event class | PascalCase | `{Subject}{PastVerb}Event` | `OrderSubmittedEvent` |
| Event type | SCREAMING_SNAKE_CASE | `{SUBJECT}_{PAST_VERB}` | `ORDER_SUBMITTED` |
| State (leaf) | snake_case | adjective / participle | `awaiting_payment` |
| State (parent) | snake_case | noun (namespace) | `payment` |
| Action class | PascalCase | `{Verb}{Object}Action` | `SendNotificationAction` |
| Guard class | PascalCase | `{Prefix}{Condition}Guard` | `IsPaymentValidGuard` |
| Validation Guard | PascalCase | `{Prefix}{Condition}ValidationGuard` | `IsAmountValidValidationGuard` |
| Calculator class | PascalCase | `{Subject}{Noun}Calculator` | `OrderTotalCalculator` |
| Output class | PascalCase | `{Subject}{Noun}Output` | `InvoiceSummaryOutput` |
| Machine class | PascalCase | `{Domain}Machine` | `OrderWorkflowMachine` |
| Machine ID | snake_case | `{domain_name}` | `order_workflow` |
| Context class | PascalCase | `{Domain}Context` | `OrderWorkflowContext` |
| Timer event | SCREAMING_SNAKE_CASE | Same as event types | `ORDER_EXPIRED`, `BILLING` |
| `then` event | SCREAMING_SNAKE_CASE | Same as event types | `MAX_RETRIES` |
| Context keys | camelCase | `$descriptiveName` | `totalAmount` |
| Event payload keys | camelCase | `$descriptiveName` | `transactionId` |
| Config keys | snake_case | `{descriptive_name}` | `should_persist` |
| Inline behavior key | camelCase | `{descriptiveName}{Type}` | `sendEmailAction` |
| Scenario name | snake_case | `{descriptive_name}` | `express_checkout` |
| Eloquent column | snake_case | `{domain}_mre` | `order_workflow_mre` |
| Endpoint action class | PascalCase | `{DescriptiveName}EndpointAction` | `CancelEndpointAction` |
| Endpoint action inline key | camelCase | `{descriptiveName}EndpointAction` | `cancelEndpointAction` |
| Endpoint output class | PascalCase | `{EventDerived}EndpointOutput` | `GuarantorSavedEndpointOutput` |
| Endpoint output inline key | camelCase | `{eventDerived}EndpointOutput` | `guarantorSavedEndpointOutput` |
| MachineInput class | PascalCase | `{Domain}Input` | `PaymentInput` |
| MachineOutput class | PascalCase | `{Domain}Output` or `{State}Output` | `PaymentOutput`, `ApprovedOutput` |
| MachineFailure class | PascalCase | `{Domain}Failure` | `PaymentFailure` |
| Resolver class | PascalCase | `{Description}Resolver` | `ExpiredApplicationsResolver` |
| Endpoint URI (auto) | kebab-case | from event type | `/farmer-saved` |
| Route name (auto) | snake_case | from event type | `machines.application.farmer_saved` |

## Class Names vs Internal References

Every element in EventMachine has two identities: its **PHP class name** and the **string key** used to reference it in configuration. Here is how they relate:

| Element | Class Name (PascalCase) | Inline Key (camelCase) | Config String |
|---------|------------------------|----------------------|---------------|
| Action | `SendEmailAction` | `sendEmailAction` | `'sendEmailAction'` |
| Guard | `IsPaymentValidGuard` | `isPaymentValidGuard` | `'isPaymentValidGuard'` |
| Validation Guard | `IsAmountValidValidationGuard` | `isAmountValidValidationGuard` | `'isAmountValidValidationGuard'` |
| Calculator | `OrderTotalCalculator` | `orderTotalCalculator` | `'orderTotalCalculator'` |
| Output | `InvoiceSummaryOutput` | `invoiceSummaryOutput` | `'invoiceSummaryOutput'` |
| Event | `OrderSubmittedEvent` | — | `'ORDER_SUBMITTED'` |
| Machine | `OrderWorkflowMachine` | — | `'order_workflow'` |
| Context | `OrderWorkflowContext` | — | — |
| Endpoint Action | `CancelEndpointAction` | — | `CancelEndpointAction::class` |
| Endpoint Output | `OrderDetailEndpointOutput` | `orderDetailEndpointOutput` | `'orderDetailEndpointOutput'` |

The pattern is straightforward:

- **Class name** → PascalCase with type suffix (`SendEmailAction`)
- **Inline key** → camelCase version of the class name (`sendEmailAction`)
- **Event type** → SCREAMING_SNAKE_CASE derived from meaning (`ORDER_SUBMITTED`)
- **Machine ID** → snake_case derived from domain name (`order_workflow`)

```php no_run
MachineDefinition::define(
    config: [
        'id'      => 'order_workflow',                    // snake_case
        'initial' => 'idle',
        'states'  => [
            'idle' => [
                'on' => [
                    'ORDER_SUBMITTED' => [                 // SCREAMING_SNAKE_CASE
                        'target'  => 'submitted',
                        'guards'  => 'isPaymentValidGuard', // camelCase + Guard suffix
                        'actions' => ['sendEmailAction'],   // camelCase + Action suffix
                    ],
                ],
            ],
        ],
    ],
    behavior: [
        'actions' => [
            'sendEmailAction' => SendEmailAction::class,   // key matches class name
        ],
        'guards' => [
            'isPaymentValidGuard' => IsPaymentValidGuard::class,
        ],
    ],
);
```

::: tip Why Keep the Suffix in Inline Keys?
When you see `'sendEmail'` in a config, it's unclear whether it's an action, a guard, or an event handler. But `'sendEmailAction'` immediately tells you its role. The suffix adds clarity, especially in `entry`, `exit`, and `guards` fields where different behavior types can appear:
```php ignore
'entry'  => 'initializeOrderAction',   // clearly an action
'guards' => 'hasItemsGuard',           // clearly a guard
```
:::

## Events

Events represent **things that have happened** — they are facts, not commands. Always name them in the **past tense** with an `Event` suffix.

### Event Classes

```php ignore
// Class name: {Subject}{PastVerb}Event — PascalCase
class OrderSubmittedEvent extends EventBehavior { ... }
class PaymentReceivedEvent extends EventBehavior { ... }
class DocumentsUploadedEvent extends EventBehavior { ... }
class ItemAddedToCartEvent extends EventBehavior { ... }
```

The `getType()` method is **automatically derived** from the class name — strip the `Event` suffix, convert to `SCREAMING_SNAKE_CASE`. You don't need to implement it:

```php no_run
class OrderSubmittedEvent extends EventBehavior
{
    // getType() auto-generates 'ORDER_SUBMITTED' — no override needed!
}
```

Override `getType()` only when the auto-generated type doesn't match your needs (e.g., legacy compatibility):

```php no_run
class CallerEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'TEST_EVENT'; // explicit override
    }
}
```

::: danger Avoid Abbreviations
Don't abbreviate event types. Use `ORDER_SUBMITTED` instead of `ORD_SUB` or `OS`. Abbreviated types are cryptic and make debugging harder.
:::

::: warning Don't prefix event types with the machine name
Event types should describe **what happened**, not which machine they belong to. The `machine_id` column and internal event format already carry machine identity. Use `ADDRESS_INFO_EDIT_REQUESTED`, not `CAR_SALES_ADDRESS_INFO_EDIT_REQUESTED`.
:::

### Event Types in Configuration

When referencing events as string keys in the machine config, use `SCREAMING_SNAKE_CASE`:

```php ignore
'on' => [
    'ORDER_SUBMITTED'    => 'processing',
    'PAYMENT_RECEIVED'   => 'paid',
    'DOCUMENTS_UPLOADED' => 'under_review',
],
```

### Multi-Word Events

For events with multiple words, the auto-generation handles separation correctly:

| Class Name | Auto-generated `getType()` |
|------------|---------------------------|
| `OrderSubmittedEvent` | `ORDER_SUBMITTED` |
| `PaymentMethodUpdatedEvent` | `PAYMENT_METHOD_UPDATED` |
| `UserEmailVerifiedEvent` | `USER_EMAIL_VERIFIED` |
| `BulkImportCompletedEvent` | `BULK_IMPORT_COMPLETED` |

### Raised Events

Raised events follow the same naming rules. When an action raises an event internally, use `SCREAMING_SNAKE_CASE`:

```php
use Tarfinlabs\EventMachine\Behavior\ActionBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]
use Tarfinlabs\EventMachine\Behavior\EventBehavior; // [!code hide]

class ProcessPaymentAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): void
    {
        // ... process payment ...

        $this->raise('PAYMENT_PROCESSED', ['transactionId' => $txId]);
    }
}
```

In machine configuration, raised event targets use the same `SCREAMING_SNAKE_CASE`:

```php ignore
'on' => [
    'PAYMENT_PROCESSED'   => 'paid',
    'VALIDATION_COMPLETED' => 'verified',
],
```

### Event Payloads

Event payload keys are **business data** — use `camelCase`, same as context keys:

```php no_run
class PaymentReceivedEvent extends EventBehavior
{
    public ?array $payload = [
        'transactionId' => null,    // camelCase
        'amountPaid'    => null,    // camelCase
        'paymentMethod' => null,    // camelCase
    ];
}
```

When sending events with inline payloads:

```php ignore
// raise() with payload
$this->raise('PAYMENT_PROCESSED', [
    'transactionId' => $txId,       // camelCase
    'gatewayRef'    => $ref,        // camelCase
]);

// send() with payload
$machine->send([
    'type'    => 'ORDER_SUBMITTED',
    'payload' => [
        'orderId'     => $order->id,    // camelCase
        'totalAmount' => $order->total,  // camelCase
    ],
]);

// sendTo() / dispatchTo() with payload
$this->sendTo(TargetMachine::class, $rootEventId, [
    'type'    => 'STATUS_UPDATED',
    'payload' => ['newStatus' => 'approved'],  // camelCase
]);
```

::: tip Why Past Tense?
Events describe facts — something that already occurred. Using past tense (`OrderSubmitted`) instead of imperative (`SubmitOrder`) makes the intent clear and avoids confusion with commands or actions.
:::

## States

States represent **conditions** — what the machine currently "is". A state is not an action being performed; it is a description of the system's current situation.

### The "is" Test

Every state name must complete the sentence: **"The {entity} is ___"**

```
"The order is idle"               ✓
"The order is processing"         ✓
"The order is awaiting_payment"   ✓
"The order is submitted"          ✓

"The order is submit"             ✗ (imperative verb)
"The order is payment"            ✗ (noun — ambiguous)
```

If the name doesn't read naturally in this sentence, it's probably not a good state name.

### Three Grammatical Forms

State names fall into three categories, each serving a specific purpose:

#### 1. Pure Adjectives — Stable / Resting States

For states where the system is at rest, waiting, or in a steady condition:

```php ignore
'idle'      => [...],   // "The order is idle"
'active'    => [...],   // "The user is active"
'ready'     => [...],   // "The document is ready"
'pending'   => [...],   // "The request is pending"
'overdue'   => [...],   // "The invoice is overdue"
'suspended' => [...],   // "The account is suspended"
```

#### 2. Past Participles (-ed / -en) — Completed Action Results

For states entered after an action has completed. This is the most common form and naturally pairs with the event that caused the transition:

```php ignore
'submitted' => [...],   // entered via ORDER_SUBMITTED
'paid'      => [...],   // entered via PAYMENT_RECEIVED
'shipped'   => [...],   // entered via SHIPMENT_DISPATCHED
'verified'  => [...],   // entered via IDENTITY_VERIFIED
'frozen'    => [...],   // entered via ACCOUNT_FROZEN
'approved'  => [...],   // entered via REQUEST_APPROVED
```

#### 3. Present Participles (-ing) — Ongoing Processes

For states where the system is actively doing something and will transition out when the process finishes:

```php ignore
'processing'  => [...],   // actively working on something
'validating'  => [...],   // validation in progress
'retrying'    => [...],   // retry attempt in progress
'calculating' => [...],   // computation running
'uploading'   => [...],   // file upload in progress
```

::: tip When to Use -ing vs -ed?
Use **-ing** when there is genuinely ongoing work happening in that state (e.g., an async job, a timeout, or waiting for an external callback). Use **-ed** when the state represents a settled condition after something happened.

A good heuristic: if the state will transition out **on its own** (via `@always`, a timer, or a callback), it's likely an `-ing` state. If it waits for an **external event**, it's likely an `-ed` or adjective state.
:::

### The Event → State Derivation

A powerful pattern is to **derive the state name from the event** that causes entry into that state. This creates a natural, consistent relationship between events and states:

| Event | Target State |
|-------|-------------|
| `ORDER_SUBMITTED` | `submitted` |
| `PAYMENT_RECEIVED` | `paid` |
| `REVIEW_STARTED` | `under_review` |
| `DOCUMENTS_UPLOADED` | `awaiting_verification` |
| `ORDER_COMPLETED` | `completed` |

```php ignore
'states' => [
    'idle' => [
        'on' => [
            'ORDER_SUBMITTED' => 'submitted',    // event → past participle
        ],
    ],
    'submitted' => [
        'on' => [
            'PAYMENT_RECEIVED' => 'paid',         // event → past participle
        ],
    ],
    'paid' => [
        'on' => [
            'SHIPMENT_DISPATCHED' => 'shipped',   // event → past participle
        ],
    ],
],
```

This pattern makes your machine self-documenting — you can read the event-to-state mapping and immediately understand the flow.

### Multi-Word States

Always use `snake_case`. Combine participles with nouns or prepositions for clarity:

```php ignore
// Participle + noun
'awaiting_payment'     => [...],   // "The order is awaiting payment"
'pending_approval'     => [...],   // "The request is pending approval"

// Adverb + participle
'partially_fulfilled'  => [...],   // "The order is partially fulfilled"
'manually_overridden'  => [...],   // "The setting is manually overridden"

// Preposition + noun
'under_review'         => [...],   // "The application is under review"
'on_hold'              => [...],   // "The shipment is on hold"
'in_transit'           => [...],   // "The package is in transit"
```

### What to Avoid

```php ignore
// Imperative verbs — states are conditions, not commands
'process'   // → 'processing' or 'processed'
'submit'    // → 'submitted'
'validate'  // → 'validating' or 'validated'

// Bare nouns — ambiguous, don't describe a condition
'payment'   // → 'awaiting_payment' or 'paid'
'review'    // → 'under_review' or 'reviewed'
'error'     // → 'failed' or 'errored'

// camelCase or PascalCase — use snake_case
'awaitingPayment'  // → 'awaiting_payment'
'UnderReview'      // → 'under_review'

// Generic or numbered names — not descriptive
'state1'    // → use a meaningful name
'step_two'  // → describe the condition, not the sequence
'next'      // → describe what condition the system is in
```

::: warning Why Not Bare Nouns?
`payment` as a state name is ambiguous — does it mean "awaiting payment", "payment received", or "payment processing"? A participle or adjective removes ambiguity: `awaiting_payment`, `paid`, or `processing_payment` each tell a clear story.
:::

### Final States

Final states represent the end of a machine's lifecycle. Use **past participles** that imply completion or termination:

```php ignore
'states' => [
    'completed'  => ['type' => 'final'],   // successful completion
    'cancelled'  => ['type' => 'final'],   // cancelled by user/system
    'rejected'   => ['type' => 'final'],   // rejected by business rule
    'expired'    => ['type' => 'final'],   // timed out
    'archived'   => ['type' => 'final'],   // moved to archive
],
```

### Hierarchical (Compound) States

Parent states serve as **namespaces** — they group related child states. This is the **one exception** where nouns are acceptable, because the parent isn't describing a condition; it's categorizing a phase of the workflow:

```php ignore
'states' => [
    // Parent: noun (namespace) — OK here
    'payment' => [
        'initial' => 'pending',
        'states'  => [
            // Children: adjectives/participles (conditions)
            'pending'    => [...],   // "The payment is pending"
            'processing' => [...],   // "The payment is processing"
            'settled'    => [...],   // "The payment is settled"
            'refunded'   => [...],   // "The payment is refunded"
        ],
    ],
    'shipping' => [
        'initial' => 'preparing',
        'states'  => [
            'preparing'  => [...],   // "The shipping is preparing"
            'in_transit' => [...],   // "The shipping is in transit"
            'delivered'  => [...],   // "The shipping is delivered"
        ],
    ],
],
```

Notice how the "is" test adapts naturally: **"The {parent} is {child}"** — "The payment is pending", "The shipping is in transit".

### Parallel States and Regions

Parallel state regions follow the same rule as compound parents — use **nouns** as region names:

```php ignore
'fulfillment' => [
    'type'   => 'parallel',
    'states' => [
        // Region names: nouns (namespaces for parallel tracks)
        'payment'   => [
            'initial' => 'pending',
            'states'  => [
                'pending'  => [...],
                'settled'  => [...],
            ],
        ],
        'shipping'  => [
            'initial' => 'preparing',
            'states'  => [
                'preparing' => [...],
                'shipped'   => [...],
            ],
        ],
        'documents' => [
            'initial' => 'awaiting',
            'states'  => [
                'awaiting'  => [...],
                'collected' => [...],
            ],
        ],
    ],
],
```

### State ID References (`#id`)

When targeting states across machine boundaries or deep hierarchies, use the `#` prefix with the machine ID:

```php ignore
// Target a state by its absolute ID
'on' => [
    'REVIEW_APPROVED' => '#order_workflow.approved',
],
```

The ID after `#` is the **machine ID** (snake_case), followed by a dot and the **state path**:

```
#machine_id.parent_state.child_state
```

### Complete Example

Here is an order workflow that demonstrates all the patterns:

```php ignore
'states' => [
    // Adjective — stable resting state
    'idle' => [
        'on' => ['ORDER_SUBMITTED' => 'submitted'],
    ],

    // Past participle — entered after ORDER_SUBMITTED
    'submitted' => [
        'on' => ['PAYMENT_RECEIVED' => 'processing'],
    ],

    // Present participle — ongoing work
    'processing' => [
        'on' => [
            'FULFILLMENT_COMPLETED' => 'shipped',
            'PROCESSING_FAILED'     => 'failed',
        ],
    ],

    // Past participle — entered after FULFILLMENT_COMPLETED
    'shipped' => [
        'on' => ['DELIVERY_CONFIRMED' => 'delivered'],
    ],

    // Past participle — entered after DELIVERY_CONFIRMED
    'delivered' => [
        'on' => ['ORDER_CLOSED' => 'completed'],
    ],

    // Past participle — terminal failure state
    'failed' => [
        'on' => ['RETRY_REQUESTED' => 'processing'],
    ],

    // Past participle — final state
    'completed' => ['type' => 'final'],

    // Past participle — final state
    'cancelled' => ['type' => 'final'],
],
```

## Actions

Actions represent **side effects** — things the machine does. Name them as **verb phrases** with an `Action` suffix.

### Action Classes

```php ignore
// Class name: {Verb}{Object}Action — PascalCase
class SendNotificationAction extends ActionBehavior { ... }
class UpdateInventoryAction extends ActionBehavior { ... }
class ChargePaymentMethodAction extends ActionBehavior { ... }
class GenerateInvoicePdfAction extends ActionBehavior { ... }
```

### Inline Behavior Keys

When registering actions in behavior arrays, use `camelCase` with the type suffix:

```php ignore
'behavior' => [
    'actions' => [
        'sendNotificationAction'    => SendNotificationAction::class,
        'updateInventoryAction'     => UpdateInventoryAction::class,
        'chargePaymentMethodAction' => ChargePaymentMethodAction::class,
    ],
],
```

### Entry and Exit Actions

Entry actions typically use verbs that describe **initialization or setup**. Exit actions use verbs that describe **cleanup or teardown**:

```php ignore
'states' => [
    'processing' => [
        'entry' => 'startProcessingAction',    // initialize, start, load, notify
        'exit'  => 'logCompletionAction',      // log, cleanup, save, release, stop
        'on'    => [...],
    ],
],
```

Common entry action verbs: `initialize`, `start`, `load`, `notify`, `acquire`, `begin`

Common exit action verbs: `log`, `cleanup`, `save`, `release`, `stop`, `flush`

### Multi-Word Actions

Start with the verb, then describe the object:

| Class Name | Inline Key |
|------------|-----------|
| `SendNotificationAction` | `sendNotificationAction` |
| `UpdateInventoryAction` | `updateInventoryAction` |
| `ChargePaymentMethodAction` | `chargePaymentMethodAction` |
| `GenerateInvoicePdfAction` | `generateInvoicePdfAction` |

::: tip Use Specific Verbs
Prefer specific verbs over generic ones. `sendNotificationAction` is better than `handleNotificationAction`. Other good verbs: `create`, `update`, `delete`, `calculate`, `validate`, `notify`, `log`, `sync`, `assign`.
:::

## Guards

Guards represent **conditions** — boolean checks that determine whether a transition should proceed. Name them with a **boolean prefix** and a `Guard` suffix.

### Boolean Prefixes

| Prefix | Use For | Example |
|--------|---------|---------|
| `is` | State or identity checks | `IsPaymentValidGuard` |
| `has` | Possession or existence checks | `HasSufficientBalanceGuard` |
| `can` | Capability or permission checks | `CanUserApproveGuard` |
| `should` | Business rule checks | `ShouldRetryPaymentGuard` |

### Guard Classes

```php ignore
// Class name: {Prefix}{Condition}Guard — PascalCase
class IsPaymentValidGuard extends GuardBehavior { ... }
class HasSufficientBalanceGuard extends GuardBehavior { ... }
class CanUserApproveGuard extends GuardBehavior { ... }
class ShouldRetryPaymentGuard extends GuardBehavior { ... }
```

### Validation Guards

For guards that perform Laravel validation, use `ValidationGuard` suffix:

```php ignore
// Class name: {Prefix}{Condition}ValidationGuard — PascalCase
class IsAmountValidValidationGuard extends ValidationGuardBehavior { ... }
class IsEmailFormatValidValidationGuard extends ValidationGuardBehavior { ... }
```

### Inline Behavior Keys

```php ignore
'behavior' => [
    'guards' => [
        'isPaymentValidGuard'       => IsPaymentValidGuard::class,
        'hasSufficientBalanceGuard' => HasSufficientBalanceGuard::class,
        'canUserApproveGuard'       => CanUserApproveGuard::class,
    ],
],
```

## Calculators

Calculators compute values for the context. Name them with a **descriptive noun** and a `Calculator` suffix.

```php ignore
// Class name: {Subject}{Noun}Calculator — PascalCase
class OrderTotalCalculator extends CalculatorBehavior { ... }
class ShippingCostCalculator extends CalculatorBehavior { ... }
class TaxAmountCalculator extends CalculatorBehavior { ... }
class WeightedAverageCalculator extends CalculatorBehavior { ... }
```

Inline keys:

```php ignore
'behavior' => [
    'calculators' => [
        'orderTotalCalculator'   => OrderTotalCalculator::class,
        'shippingCostCalculator' => ShippingCostCalculator::class,
    ],
],
```

## Outputs

Outputs compute the final output of a state machine. Name them with a **descriptive noun** and an `Output` suffix.

```php ignore
// Class name: {Subject}{Noun}Output — PascalCase
class InvoiceSummaryOutput extends OutputBehavior { ... }
class OrderConfirmationOutput extends OutputBehavior { ... }
class RiskAssessmentOutput extends OutputBehavior { ... }
```

## Typed Contracts

Typed contracts (`MachineInput`, `MachineOutput`, `MachineFailure`) follow domain-driven naming. Constructor parameters use `camelCase`.

### MachineInput

Name after the **domain** the child machine serves. The suffix is always `Input`:

```php ignore
// Class name: {Domain}Input — PascalCase
class PaymentInput extends MachineInput { ... }
class VerificationInput extends MachineInput { ... }
class ShippingInput extends MachineInput { ... }
```

### MachineOutput

Name after the **domain** or the **final state** the output represents. The suffix is always `Output`:

```php ignore
// Domain-scoped (single output for the machine)
class PaymentOutput extends MachineOutput { ... }

// State-scoped (different output per final state)
class ApprovedOutput extends MachineOutput { ... }
class RejectedOutput extends MachineOutput { ... }
```

### MachineFailure

Name after the **domain** the failure relates to. The suffix is always `Failure`:

```php ignore
class PaymentFailure extends MachineFailure { ... }
class VerificationFailure extends MachineFailure { ... }
```

### Constructor Parameters

All constructor parameters use `camelCase`, consistent with context keys and event payloads:

```php ignore
class PaymentInput extends MachineInput
{
    public function __construct(
        public readonly string $orderId,       // camelCase
        public readonly int $totalAmount,      // camelCase
        public readonly ?string $couponCode,   // camelCase
    ) {}
}
```

## Machine Definition

### Machine Classes

```php ignore
// Class name: {Domain}Machine — PascalCase
class OrderWorkflowMachine extends MachineDefinition { ... }
class PaymentProcessingMachine extends MachineDefinition { ... }
class UserOnboardingMachine extends MachineDefinition { ... }
```

### Machine IDs

Use `snake_case` for machine IDs in configuration. The ID is derived from the domain name without the `Machine` suffix:

```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]

MachineDefinition::define(
    config: [
        'id' => 'order_workflow',      // snake_case, no Machine suffix
        // ...
    ],
);
```

| Machine Class | Machine ID |
|--------------|-----------|
| `OrderWorkflowMachine` | `order_workflow` |
| `PaymentProcessingMachine` | `payment_processing` |
| `UserOnboardingMachine` | `user_onboarding` |
| `TrafficLightsMachine` | `traffic_lights` |

The machine ID appears in internal event names. With snake_case, these read naturally:

```
order_workflow.state.processing.enter
order_workflow.transition.ORDER_SUBMITTED.start
order_workflow.guard.isPaymentValidGuard.pass
```

### Eloquent Column Names

When using `MachineCast`, column names should use `snake_case` with an `_mre` suffix (Machine Root Event):

```php ignore
// Column name: {domain}_mre — snake_case
protected $casts = [
    'order_workflow_mre'      => MachineCast::class,
    'payment_processing_mre'  => MachineCast::class,
];
```

The `_mre` suffix makes it clear that the column stores a machine root event reference, not a plain string or JSON field.

## Context and Business Data

### The Core Rule: Config vs Data

EventMachine has two kinds of string keys, each with its own convention:

| Kind | Convention | Where | Example |
|------|-----------|-------|---------|
| **Framework config** | `snake_case` | `config:` block, Laravel settings | `should_persist`, `initial` |
| **Business data** | `camelCase` | Context, payloads, input, output | `totalAmount`, `orderId` |

The distinction: **"Are you configuring the framework, or carrying business data?"**

```php ignore
MachineDefinition::define(
    config: [
        'id'             => 'order_workflow',     // config → snake_case
        'should_persist' => true,                 // config → snake_case
        'initial'        => 'idle',               // config → snake_case
        'context'        => [
            'totalAmount'   => 0,                 // business data → camelCase
            'customerEmail' => null,              // business data → camelCase
        ],
    ],
);
```

### Context Keys — camelCase

Both inline array keys and typed class properties use `camelCase`:

```php ignore
// Inline array context
'context' => [
    'totalAmount'    => 0,
    'itemsCount'     => 0,
    'customerEmail'  => null,
    'retryCount'     => 0,
    'lastErrorCode'  => null,
    'isPriority'     => false,
],
```

```php
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]

// Typed context class — same camelCase convention
class OrderWorkflowContext extends ContextManager
{
    public int $totalAmount = 0;
    public int $itemsCount = 0;
    public ?string $customerEmail = null;
    public int $retryCount = 0;
    public ?string $lastErrorCode = null;
    public bool $isPriority = false;
}
```

### Event Payloads — camelCase

When raising events or passing payloads between machines, use `camelCase` for data keys:

```php ignore
// raise() payload
$this->raise('PAYMENT_PROCESSED', ['transactionId' => $txId]);

// sendTo() payload
$this->sendTo(TargetMachine::class, $rootEventId, [
    'type'    => 'UPDATE_STATUS',
    'payload' => ['newStatus' => 'approved'],
]);
```

### Child Delegation Data — camelCase

Data flowing between parent and child machines uses `camelCase`:

```php ignore
// Parent → Child (input)
'delegating' => [
    'machine' => PaymentMachine::class,
    'input'    => ['orderId', 'totalAmount'],
],

// Child → Parent (output on final state)
'completed' => [
    'type'   => 'final',
    'output' => ['paymentId', 'transactionRef'],
],
```

### What to Avoid

```php ignore
// Don't use abbreviations
'amt'           // → 'amount'
'qty'           // → 'quantity'
'cnt'           // → 'count'

// Don't use generic names
'data'          // → 'orderData' or a specific key
'value'         // → 'paymentValue' or a specific key
'status'        // → 'paymentStatus' (machine state handles status)

// Don't use snake_case for business data
'total_amount'  // → 'totalAmount'
'order_id'      // → 'orderId'
```

## Scenarios

Scenario names use `snake_case` with descriptive, domain-specific identifiers:

```php no_run
MachineDefinition::define(
    config: [
        'scenarios_enabled' => true,
        'states' => [
            'idle' => [
                'on' => [
                    'ORDER_SUBMITTED' => [
                        [
                            'target'       => 'express_processing',
                            'scenarioType' => 'express_checkout',
                        ],
                        [
                            'target'       => 'standard_processing',
                            'scenarioType' => 'standard_checkout',
                        ],
                    ],
                ],
            ],
        ],
    ],
);
```

Good scenario names describe the **business variant**, not technical details:

```php ignore
// Descriptive business variants
'express_checkout'      // fast-track order flow
'enterprise_onboarding' // multi-step enterprise setup
'ab_test_v2'            // A/B test variant

// Avoid generic names
'scenario_1'            // → describe what makes it different
'test'                  // → describe the business case
```

## Configuration Keys

All configuration keys should use `snake_case`:

```php ignore
MachineDefinition::define(
    config: [
        'id'                => 'order_workflow',
        'initial'           => 'idle',
        'context'           => [...],
        'states'            => [...],
        'should_persist'    => true,
        'scenarios_enabled' => false,
    ],
);
```

::: info Internal Keys — `@` Prefix Convention
Internal framework keys use the `@` prefix: `@always`, `@done`, `@fail`. These are distinct from user-defined event types (`SCREAMING_SNAKE_CASE`) and state names (`snake_case`). The only remaining XState-inherited camelCase key is `scenarioType`.
:::

## Endpoints

Endpoint-related classes follow the same suffix conventions as other behaviors but live in a dedicated `Endpoints/` directory.

### Endpoint Action Classes

Endpoint actions handle HTTP lifecycle hooks (before/after/onException). Name them with a **descriptive name** and an `EndpointAction` suffix:

```php ignore
// Class name: {DescriptiveName}EndpointAction — PascalCase
class CancelEndpointAction extends MachineEndpointAction { ... }
class StartEndpointAction extends MachineEndpointAction { ... }
class ApproveEndpointAction extends MachineEndpointAction { ... }
```

Inline keys use camelCase:

```php ignore
'CANCEL' => [
    'action' => CancelEndpointAction::class,
],
```

### Endpoint Output Classes

Endpoint outputs customize the HTTP response. Name them with the **event-derived name** and an `EndpointOutput` suffix:

```php ignore
// Class name: {EventDerived}EndpointOutput — PascalCase
class GuarantorSavedEndpointOutput extends OutputBehavior { ... }
class ApprovedWithInitiativeEndpointOutput extends OutputBehavior { ... }
class PriceEndpointOutput extends OutputBehavior { ... }
```

Inline keys use camelCase and are referenced in the endpoint definition:

```php ignore
'behavior' => [
    'outputs' => [
        'guarantorSavedEndpointOutput' => GuarantorSavedEndpointOutput::class,
    ],
],
```

### Endpoint URIs

URIs are auto-generated from event types by converting `SCREAMING_SNAKE_CASE` to `kebab-case`. If the event type ends with `_EVENT`, that suffix is automatically stripped:

| Event Type | Auto-Generated URI |
|------------|-------------------|
| `FARMER_SAVED` | `/farmer-saved` |
| `APPROVED_WITH_INITIATIVE` | `/approved-with-initiative` |
| `CONSENT_GRANTED_EVENT` | `/consent-granted` |
| `ORDER_SUBMITTED` | `/order-submitted` |

You can override the auto-generated URI with an explicit `uri` key in the endpoint definition.

### Endpoint Config Keys

When defining endpoints in `MachineDefinition::define()`, config keys use `snake_case` to match the existing config convention:

```php ignore
MachineDefinition::define(
    // ...
    endpoints: [
        'SUBMIT_ORDER' => [
            'uri'        => '/submit',        // snake_case (kebab-case value)
            'method'     => 'POST',           // HTTP method
            'action'     => SubmitEndpointAction::class,
            'output' => 'orderSummaryOutput',
            'statusCode' => 201,              // camelCase (inherited from XState convention)
            'middleware'  => ['auth:api'],
        ],
    ],
);
```

### MachineRouter Options

When registering routes via `MachineRouter::register()`, options use `camelCase` for PHP method argument consistency:

| Option | Type | Description |
|--------|------|-------------|
| `prefix` | `string` | URL prefix for all routes |
| `model` | `string` | Eloquent model FQCN (required when `modelFor` is set) |
| `attribute` | `string` | Model attribute that returns the Machine (required when `modelFor` is set) |
| `create` | `bool` | Generate `POST /create` endpoint |
| `machineIdFor` | `string[]` | Event types routed by machine ID |
| `modelFor` | `string[]` | Event types routed by Eloquent model binding |
| `middleware` | `string[]` | Middleware applied to all routes in the group |
| `name` | `string` | Route name prefix (defaults to machine ID) |
| `only` | `string[]` | Register only these event endpoints (mutually exclusive with `except`) |
| `except` | `string[]` | Register all except these event endpoints (mutually exclusive with `only`) |

### Route Names

Route names are auto-generated from the machine name prefix and the event type in lowercase `snake_case`:

| Name Prefix | Event Type | Route Name |
|-------------|------------|------------|
| `machines.application` | `FARMER_SAVED` | `machines.application.farmer_saved` |
| `machines.application` | `APPROVED_WITH_INITIATIVE` | `machines.application.approved_with_initiative` |

## Resolvers

Resolvers determine which machine instances receive a scheduled event. Name them with a **descriptive name** and a `Resolver` suffix.

### Resolver Classes

```php ignore
// Class name: {Description}Resolver — PascalCase
class ExpiredApplicationsResolver implements ScheduleResolver { ... }
class UnpaidOrdersResolver implements ScheduleResolver { ... }
class ActiveSubscriptionsResolver implements ScheduleResolver { ... }
```

The description should indicate **which instances** the resolver targets, not the event type:

| Good | Why |
|------|-----|
| `ExpiredApplicationsResolver` | Describes the instances it finds |
| `UnpaidOrdersResolver` | Clear business meaning |
| `ActiveSubscriptionsResolver` | Self-documenting |

| Avoid | Why |
|-------|-----|
| `CheckExpiryResolver` | Describes the event, not the instances |
| `ApplicationResolver` | Too generic — which applications? |
| `DailyResolver` | Describes the schedule, not the target |

## File Organization

Organize behavior classes in a directory structure that mirrors the machine domain:

```
app/
└── MachineDefinitions/
    └── OrderWorkflow/
        ├── OrderWorkflowMachine.php
        ├── OrderWorkflowContext.php
        ├── Actions/
        │   ├── SendConfirmationEmailAction.php
        │   ├── UpdateInventoryAction.php
        │   └── ChargePaymentMethodAction.php
        ├── Guards/
        │   ├── IsPaymentValidGuard.php
        │   ├── HasSufficientBalanceGuard.php
        │   └── IsAmountValidValidationGuard.php
        ├── Events/
        │   ├── OrderSubmittedEvent.php
        │   └── PaymentReceivedEvent.php
        ├── Calculators/
        │   └── OrderTotalCalculator.php
        ├── Outputs/
        │   └── OrderConfirmationOutput.php
        ├── Endpoints/
        │   ├── Actions/
        │   │   ├── CancelEndpointAction.php
        │   │   └── StartEndpointAction.php
        │   └── Outputs/
        │       └── OrderDetailEndpointOutput.php
        └── Resolvers/
            └── ExpiredApplicationsResolver.php
```

## @always Chain Termination

Every `@always` transition chain must eventually reach either:
- A state without `@always` (terminal)
- A guard that will eventually fail (conditional exit)

The `max_transition_depth` config (default: 100) acts as a safety net, not flow control. If your legitimate chain exceeds 100 steps, increase the limit rather than relying on it as a loop breaker.

## Summary

The key principles behind these conventions:

1. **Events are facts** — past tense, describing what happened (`OrderSubmitted`, not `SubmitOrder`)
2. **States are conditions** — adjectives or participles, passing the "is" test (`processing`, not `process`)
3. **Actions are verbs** — describing what the machine does (`SendNotification`, not `Notification`)
4. **Guards are questions** — boolean predicates with `is`/`has`/`can`/`should` prefix (`IsPaymentValid`, not `PaymentValid`)
5. **Business data is camelCase, config is snake_case** — context keys, payloads, input/output use `camelCase`; framework config keys use `snake_case`
6. **Inline keys include the type suffix** — `'sendEmailAction'` not `'sendEmail'` for clarity
7. **Suffixes prevent ambiguity** — `Event`, `Action`, `Guard`, `Calculator`, `Output` suffixes on class names make the role immediately clear
8. **Consistency over cleverness** — pick one pattern and apply it everywhere
