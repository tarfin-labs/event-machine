# Event Storming

Event Storming is a workshop-based method for exploring complex business domains. By bringing together diverse stakeholders, it helps discover domain events, identify bounded contexts, and understand business processes. When combined with EventMachine, it provides a powerful foundation for designing event-driven state machines.

## What is Event Storming?

**Event Storming** is a collaborative design technique invented by Alberto Brandolini. It's a rapid, lightweight method for exploring complex business domains by focusing on **domain events** - the things that happen in your business that domain experts care about.

### Core Principles

1. **Events First**: Start with what happens, not what exists
2. **Collaborative Discovery**: Bring together people with different perspectives
3. **Visual and Tactile**: Use sticky notes and large wall spaces
4. **Iterative Exploration**: Discover, refine, and organize iteratively

### Why Event Storming for State Machines?

Event Storming naturally aligns with state machine thinking:

- **Domain Events** → **Machine Events**: Business events become state transition triggers
- **Process Flow** → **State Transitions**: Business processes map to state flows
- **Business Rules** → **Guards**: Domain constraints become transition conditions
- **Side Effects** → **Actions**: Business operations become machine actions

## The Event Storming Process

### Big Picture Event Storming

The first phase focuses on discovering all domain events across the entire business domain.

#### Workshop Setup

**Participants:**
- Domain experts (business users, product owners)
- Developers and architects
- UX designers and analysts
- Anyone with knowledge of the business process

**Materials:**
- Large wall space (or virtual whiteboard)
- Orange sticky notes (for domain events)
- Different colored notes for other elements
- Markers

#### Process Steps

1. **Chaotic Exploration** (30-45 minutes)
   - Everyone writes domain events on orange sticky notes
   - Use past tense: "Order Placed", "Payment Processed"
   - Don't organize - just discover

2. **Timeline Creation** (45-60 minutes)
   - Arrange events in chronological order
   - Identify parallel processes
   - Spot gaps and inconsistencies

3. **Hotspot Identification**
   - Mark areas of confusion with pink notes
   - Identify conflicting opinions
   - Note missing information

### Process Level Event Storming

Zoom into specific business processes to understand detailed flows.

#### Additional Elements

- **Commands** (Blue): What triggers events
- **Actors** (Yellow): Who or what executes commands
- **Read Models** (Green): Information needed to make decisions
- **Policies** (Purple): Business rules that trigger reactions

#### EventMachine Mapping

During this phase, start thinking about state machine boundaries:

```
Command → Event → State Change
Actor → Machine Context
Policy → Guard Condition
Read Model → Context Data
```

### Design Level Event Storming

Focus on individual aggregates and their state machines.

#### Aggregate Boundaries

Identify which events belong to the same aggregate:

```
Order Aggregate:
- Order Placed
- Item Added
- Payment Processed
- Order Shipped
- Order Delivered
```

This becomes your state machine scope.

## Converting to EventMachine

### From Domain Events to Machine Events

**Domain Event** (Business language):
- "Order was placed by customer"
- "Payment was processed successfully" 
- "Shipping address was updated"

**Machine Event** (Technical implementation):
```php
class OrderPlacedEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'ORDER_PLACED';
    }

    public function validatePayload(): array
    {
        return [
            'customer_id' => 'required|integer',
            'items' => 'required|array|min:1',
            'total' => 'required|numeric|min:0',
            'shipping_address' => 'required|array'
        ];
    }
}
```

### Mapping Process Flow to States

Transform your timeline into state definitions:

**Event Storming Timeline:**
```
Order Placed → Payment Pending → Payment Processed → Fulfillment Started → Order Shipped → Order Delivered
```

**EventMachine States:**
```php
'states' => [
    'pending_payment' => [
        'on' => [
            'PAYMENT_PROCESSED' => 'fulfilling'
        ]
    ],
    'fulfilling' => [
        'on' => [
            'SHIPMENT_CREATED' => 'shipped'
        ]
    ],
    'shipped' => [
        'on' => [
            'DELIVERY_CONFIRMED' => 'delivered'
        ]
    ]
]
```

### Business Rules as Guards

Convert domain policies to guard conditions:

**Domain Policy:**
"Premium customers get expedited processing"

**EventMachine Guard:**
```php
class PremiumCustomerGuard extends GuardBehavior
{
    public function __invoke(OrderContext $context): bool
    {
        return $context->customer->isPremium();
    }
}
```

### Side Effects as Actions

Transform business operations into actions:

**Domain Operation:**
"Send confirmation email when order is placed"

**EventMachine Action:**
```php
class SendOrderConfirmationAction extends ActionBehavior
{
    public function __invoke(OrderContext $context, EventDefinition $event): void
    {
        Mail::to($context->customer->email)
            ->send(new OrderConfirmationEmail($context->orderId));
            
        $context->confirmationSent = true;
        $context->confirmationSentAt = now();
    }
}
```

## Practical Example: E-commerce Order Processing

Let's walk through converting an event storming session into a working EventMachine definition.

### Event Storming Results

**Domain Events Discovered:**
- Customer Registered
- Item Added to Cart
- Cart Abandoned
- Order Placed
- Payment Authorized
- Payment Captured
- Inventory Reserved
- Order Packed
- Shipping Label Created
- Order Shipped
- Delivery Attempted
- Order Delivered
- Return Requested
- Refund Processed

**Process Flow Identified:**
```
[Guest] → Register → [Customer]
[Customer] → Add Items → [Cart with Items]
[Cart] → Place Order → [Order Placed]
[Order] → Process Payment → [Payment Confirmed]
[Order] → Reserve Inventory → [Items Reserved]
[Order] → Pack & Ship → [Order Shipped]
[Order] → Deliver → [Order Completed]
```

### Converting to Machine Definition

```php
<?php

use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class OrderMachine extends MachineDefinition
{
    public static function definition(): array
    {
        return [
            'config' => [
                'id' => 'order_processing',
                'initial' => 'draft',
                'context' => [
                    'orderId' => null,
                    'customerId' => null,
                    'items' => [],
                    'total' => 0,
                    'paymentId' => null,
                    'shippingAddress' => null,
                    'trackingNumber' => null
                ]
            ],
            'states' => [
                'draft' => [
                    'on' => [
                        'PLACE_ORDER' => [
                            'target' => 'pending_payment',
                            'actions' => ['validateOrder', 'reserveInventory']
                        ]
                    ]
                ],
                'pending_payment' => [
                    'on' => [
                        'PAYMENT_AUTHORIZED' => [
                            'target' => 'processing',
                            'actions' => 'capturePayment'
                        ],
                        'PAYMENT_FAILED' => [
                            'target' => 'payment_failed',
                            'actions' => 'releaseInventory'
                        ]
                    ]
                ],
                'processing' => [
                    'entry' => ['notifyWarehouse', 'sendConfirmation'],
                    'on' => [
                        'ORDER_PACKED' => [
                            'target' => 'shipped',
                            'actions' => 'createShippingLabel'
                        ]
                    ]
                ],
                'shipped' => [
                    'on' => [
                        'DELIVERY_CONFIRMED' => [
                            'target' => 'delivered',
                            'actions' => 'sendDeliveryNotification'
                        ],
                        'RETURN_REQUESTED' => [
                            'target' => 'return_processing',
                            'guards' => 'withinReturnWindow'
                        ]
                    ]
                ],
                'delivered' => {
                    'type' => 'final'
                }
            ],
            'behavior' => [
                'actions' => [
                    'validateOrder' => ValidateOrderAction::class,
                    'reserveInventory' => ReserveInventoryAction::class,
                    'capturePayment' => CapturePaymentAction::class,
                    'releaseInventory' => ReleaseInventoryAction::class,
                    'notifyWarehouse' => NotifyWarehouseAction::class,
                    'sendConfirmation' => SendConfirmationAction::class,
                    'createShippingLabel' => CreateShippingLabelAction::class,
                    'sendDeliveryNotification' => SendDeliveryNotificationAction::class
                ],
                'guards' => [
                    'withinReturnWindow' => WithinReturnWindowGuard::class
                ],
                'events' => [
                    'PLACE_ORDER' => PlaceOrderEvent::class,
                    'PAYMENT_AUTHORIZED' => PaymentAuthorizedEvent::class,
                    'PAYMENT_FAILED' => PaymentFailedEvent::class,
                    'ORDER_PACKED' => OrderPackedEvent::class,
                    'DELIVERY_CONFIRMED' => DeliveryConfirmedEvent::class,
                    'RETURN_REQUESTED' => ReturnRequestedEvent::class
                ]
            ]
        ];
    }
}
```

### Implementing Event Classes

Based on the event storming session, create structured events:

```php
<?php

class PlaceOrderEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'PLACE_ORDER';
    }

    public function validatePayload(): array
    {
        return [
            'customer_id' => 'required|integer|exists:customers,id',
            'items' => 'required|array|min:1',
            'items.*.sku' => 'required|string|exists:products,sku',
            'items.*.quantity' => 'required|integer|min:1',
            'shipping_address' => 'required|array',
            'shipping_address.line1' => 'required|string',
            'shipping_address.city' => 'required|string',
            'shipping_address.postal_code' => 'required|string',
            'payment_method' => 'required|string'
        ];
    }
}

class PaymentAuthorizedEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'PAYMENT_AUTHORIZED';
    }

    public function validatePayload(): array
    {
        return [
            'payment_id' => 'required|string',
            'authorization_code' => 'required|string',
            'amount' => 'required|numeric|min:0'
        ];
    }
}
```

## Tips and Best Practices

### Event Storming Workshop

1. **Keep Sessions Focused**
   - Big Picture: 4-6 hours max
   - Process Level: 2-3 hours per process
   - Design Level: 1-2 hours per aggregate

2. **Encourage Participation**
   - Everyone contributes events
   - No hierarchy during exploration
   - Domain experts lead, developers facilitate

3. **Handle Disagreements**
   - Mark conflicting views as hotspots
   - Don't resolve immediately - capture first
   - Use follow-up sessions for deep dives

4. **Stay Event-Focused**
   - Events describe what happened, not what should happen
   - Use past tense consistently
   - Focus on business-relevant events

### EventMachine Conversion

1. **Start with Core Flow**
   - Implement the happy path first
   - Add error handling and edge cases later
   - Keep the initial machine simple

2. **Group Related Events**
   - Events that modify the same data belong together
   - Consider aggregate boundaries carefully
   - Don't create too many small machines

3. **Preserve Business Language**
   - Keep event names close to domain language
   - Use the same terminology in code and documentation
   - Make the code readable by domain experts

4. **Iterate and Refine**
   - Start with basic implementation
   - Add complexity gradually
   - Get feedback from domain experts

### Common Pitfalls

1. **Over-Engineering Early**
   - Don't try to capture every detail in the first session
   - Start broad, then narrow focus
   - Iterate based on real implementation needs

2. **Ignoring Domain Experts**
   - Developers shouldn't dominate the conversation
   - Business logic belongs with business people
   - Technical constraints come later

3. **Creating Too Many Machines**
   - Not every process needs its own machine
   - Look for natural boundaries
   - Consider maintenance overhead

## Integration with EventMachine Features

### Context Management

Use event storming insights to design your context:

```php
// Based on discovered data needs
'context' => [
    'customer' => [
        'id' => null,
        'tier' => 'standard',  // From "Premium Customer" hotspot
        'email' => null
    ],
    'order' => [
        'id' => null,
        'total' => 0,
        'currency' => 'USD'
    ],
    'fulfillment' => [
        'warehouseId' => null,
        'expectedShipDate' => null,
        'trackingNumber' => null
    ]
]
```

### Testing Strategy

Event storming scenarios become test cases:

```php
public function test_premium_customer_gets_expedited_processing()
{
    // Scenario from event storming: "Premium customers skip standard review"
    $machine = OrderMachine::create([
        'customerId' => $this->premiumCustomer->id
    ]);
    
    $machine = $machine->send('PLACE_ORDER', [
        'items' => [['sku' => 'ABC123', 'quantity' => 1]],
        'total' => 99.99
    ]);
    
    // Should skip to fulfillment for premium customers
    $this->assertEquals('processing', $machine->state->value);
}
```

### Event Sourcing Benefits

EventMachine's built-in event sourcing captures your domain events:

```php
// All domain events are automatically persisted
$events = MachineEvent::where('machine_id', $orderId)
    ->orderBy('created_at')
    ->get();

// Reconstruct business timeline
foreach ($events as $event) {
    echo "{$event->type} at {$event->created_at}\n";
}
// Output:
// ORDER_PLACED at 2023-01-15 10:30:00
// PAYMENT_AUTHORIZED at 2023-01-15 10:31:15
// ORDER_PACKED at 2023-01-15 14:20:00
// ORDER_SHIPPED at 2023-01-15 16:45:00
```

## When to Use Event Storming

### Great For:
- **Complex Business Processes**: Multi-step workflows with branching logic
- **Cross-Team Projects**: When multiple departments are involved
- **Domain Discovery**: Understanding unfamiliar business areas
- **Legacy System Replacement**: Documenting existing processes
- **Microservice Boundaries**: Identifying service boundaries

### Not Ideal For:
- **Simple CRUD Operations**: Basic create/read/update/delete scenarios
- **Technical Implementation Details**: Database design, API contracts
- **Well-Understood Domains**: When the team already knows the process well
- **Solo Projects**: When you don't have access to domain experts

## Next Steps

Now that you understand event storming and its integration with EventMachine:

- **[States and Transitions](./states-and-transitions.md)** - Design your state machine structure
- **[Events and Actions](./events-and-actions.md)** - Implement the events you discovered
- **[Context Management](./context.md)** - Design context based on data needs
- **[Your First Machine](../getting-started/first-machine.md)** - Build your first implementation
- **[Testing Strategies](../testing/strategies.md)** - Test the scenarios you discovered

Event storming provides the foundation for understanding your domain. EventMachine gives you the tools to implement that understanding as robust, maintainable state machines.