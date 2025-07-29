# Machine Design

Machine design is the art and science of creating well-structured, maintainable, and performant state machines. As you gain experience with EventMachine, you'll develop an intuitive sense for optimal machine architecture - knowing when to split states, how to organize guards and actions, and where to introduce intermediate transitions for clarity and reusability.

## What is Machine Design Expertise?

**Machine Design** is the accumulated wisdom of creating state machines that are not just functional, but elegant, maintainable, and efficient. It's the difference between a machine that "works" and one that's a joy to work with.

### The Evolution of Machine Thinking

Most developers follow a predictable journey:

**Stage 1: Basic Implementation**
- Direct translation of requirements to states
- States mirror business language exactly
- Actions and guards scattered throughout

**Stage 2: Functional Competence**
- Understanding of core concepts
- Machines work but may be verbose
- Some optimization awareness

**Stage 3: Design Expertise**
- Intuitive sense for state granularity
- Recognition of reusable patterns
- Optimization for maintainability and performance

### Core Design Principles

1. **Clarity over Cleverness**: Prefer explicit, readable machines over compact but obscure ones
2. **Reusability**: Identify and extract common patterns
3. **Scalability**: Design for future growth and complexity
4. **Performance**: Consider database load and memory usage
5. **Testability**: Structure for easy testing and debugging

## State Design Patterns

### State Granularity

The most critical design decision is choosing the right level of state granularity.

#### Too Fine-Grained

```php
// POOR: Overly detailed states
'states' => [
    'validating_email' => [
        'on' => ['EMAIL_VALID' => 'validating_password']
    ],
    'validating_password' => [
        'on' => ['PASSWORD_VALID' => 'validating_terms']
    ],
    'validating_terms' => [
        'on' => ['TERMS_ACCEPTED' => 'creating_account']
    ],
    'creating_account' => [
        'on' => ['ACCOUNT_CREATED' => 'sending_welcome']
    ],
    'sending_welcome' => [
        'on' => ['EMAIL_SENT' => 'complete']
    ]
]
```

**Problems:**
- Too many states for simple validation
- Event explosion
- Hard to understand the bigger picture

#### Too Coarse-Grained

```php
// POOR: Overly broad states
'states' => [
    'processing' => [
        'on' => [
            'VALIDATION_COMPLETE' => 'complete',
            'ACCOUNT_CREATED' => 'complete',
            'EMAIL_SENT' => 'complete',
            'ERROR' => 'failed'
        ]
    ]
]
```

**Problems:**
- Unclear what "processing" actually means
- Multiple responsibilities in one state
- Hard to track progress

#### Just Right

```php
// GOOD: Balanced granularity
'states' => [
    'validating' => [
        'on' => [
            'VALIDATION_COMPLETE' => [
                'target' => 'creating_account',
                'actions' => 'processValidation'
            ],
            'VALIDATION_FAILED' => [
                'target' => 'validation_failed',
                'actions' => 'logValidationErrors'
            ]
        ]
    ],
    'creating_account' => [
        'on' => [
            'ACCOUNT_CREATED' => [
                'target' => 'complete',
                'actions' => ['sendWelcomeEmail', 'logSuccess']
            ],
            'CREATION_FAILED' => [
                'target' => 'creation_failed',
                'actions' => 'handleCreationError'
            ]
        ]
    ]
]
```

### Intermediate States for Common Patterns

Recognize when repeated guard/action combinations indicate a missing state.

#### Before: Repeated Logic

```php
// POOR: Same validation repeated everywhere
'states' => [
    'draft' => [
        'on' => [
            'SUBMIT' => [
                'target' => 'review',
                'guards' => ['hasRequiredFields', 'userCanSubmit'],
                'actions' => ['validateData', 'notifyReviewers']
            ]
        ]
    ],
    'revision' => [
        'on' => [
            'RESUBMIT' => [
                'target' => 'review',
                'guards' => ['hasRequiredFields', 'userCanSubmit'],
                'actions' => ['validateData', 'notifyReviewers']
            ]
        ]
    ]
]
```

#### After: Intermediate State

```php
// GOOD: Validation extracted to intermediate state
'states' => [
    'draft' => [
        'on' => ['SUBMIT' => 'validating']
    ],
    'revision' => [
        'on' => ['RESUBMIT' => 'validating']
    ],
    'validating' => [
        'entry' => 'validateData',
        'on' => [
            '@always' => [
                [
                    'target' => 'review',
                    'guards' => ['hasRequiredFields', 'userCanSubmit'],
                    'actions' => 'notifyReviewers'
                ],
                [
                    'target' => 'validation_failed',
                    'actions' => 'logValidationErrors'
                ]
            ]
        ]
    ]
]
```

**Benefits:**
- Single source of truth for validation logic
- Easier to modify validation rules
- Clear separation of concerns
- Better testability

### Hierarchical State Organization

Break complex machines into manageable hierarchies.

#### Flat Machine (Hard to Manage)

```php
// POOR: Flat structure for complex workflow
'states' => [
    'draft' => [...],
    'pending_manager_review' => [...],
    'pending_hr_review' => [...],
    'pending_legal_review' => [...],
    'manager_approved' => [...],
    'hr_approved' => [...],
    'legal_approved' => [...],
    'all_approved' => [...],
    'manager_rejected' => [...],
    'hr_rejected' => [...],
    'legal_rejected' => [...],
    'final_approved' => [...],
    'final_rejected' => [...]
]
```

#### Hierarchical Machine (Clear Structure)

```php
// GOOD: Hierarchical organization
'states' => [
    'draft' => [
        'on' => ['SUBMIT' => 'review_process']
    ],
    'review_process' => [
        'initial' => 'manager_review',
        'states' => [
            'manager_review' => [
                'on' => [
                    'APPROVE' => 'hr_review',
                    'REJECT' => '#rejected'
                ]
            ],
            'hr_review' => [
                'on' => [
                    'APPROVE' => 'legal_review',
                    'REJECT' => '#rejected'
                ]
            ],
            'legal_review' => [
                'on' => [
                    'APPROVE' => '#approved',
                    'REJECT' => '#rejected'
                ]
            ]
        ],
        'on' => [
            'approved' => 'final_approved',
            'rejected' => 'final_rejected'
        ]
    ]
]
```

## Guard and Action Optimization

### Identifying Repeated Patterns

Watch for these signs that indicate optimization opportunities:

1. **Same guard combinations** across multiple transitions
2. **Action sequences** that always run together
3. **Complex conditional logic** in multiple places
4. **Performance bottlenecks** from repeated database queries

### Extract Common Guards to States

#### Before: Repeated Authorization

```php
// POOR: Authorization check everywhere
'states' => [
    'editing' => [
        'on' => [
            'SAVE' => [
                'target' => 'saved',
                'guards' => ['userCanEdit', 'documentNotLocked', 'hasValidData'],
                'actions' => 'saveDocument'
            ],
            'PUBLISH' => [
                'target' => 'published',
                'guards' => ['userCanEdit', 'documentNotLocked', 'userCanPublish'],
                'actions' => 'publishDocument'
            ]
        ]
    ]
]
```

#### After: Authorization State

```php
// GOOD: Authorization extracted
'states' => [
    'editing' => [
        'on' => [
            'SAVE' => 'authorizing_save',
            'PUBLISH' => 'authorizing_publish'
        ]
    ],
    'authorizing_save' => [
        'on' => [
            '@always' => [
                [
                    'target' => 'saving',
                    'guards' => ['userCanEdit', 'documentNotLocked', 'hasValidData']
                ],
                [
                    'target' => 'authorization_failed',
                    'actions' => 'logAuthorizationFailure'
                ]
            ]
        ]
    ],
    'saving' => [
        'entry' => 'saveDocument',
        'on' => [
            '@always' => 'saved'
        ]
    ]
]
```

### Action Batching and Sequencing

Group related actions for better performance and maintainability.

#### Before: Scattered Actions

```php
// POOR: Actions scattered across transitions
'on' => [
    'PLACE_ORDER' => [
        'target' => 'processing',
        'actions' => [
            'validatePayment',
            'checkInventory',
            'calculateTax',
            'createOrder',
            'sendConfirmation',
            'updateInventory',
            'logOrder'
        ]
    ]
]
```

#### After: Organized Action Flow

```php
// GOOD: Actions organized by concern
'on' => [
    'PLACE_ORDER' => 'validating'
],

'validating' => [
    'entry' => ['validatePayment', 'checkInventory'],
    'on' => [
        '@always' => [
            [
                'target' => 'processing',
                'guards' => 'validationPassed'
            ],
            [
                'target' => 'validation_failed',
                'actions' => 'notifyValidationErrors'
            ]
        ]
    ]
],

'processing' => [
    'entry' => ['calculateTax', 'createOrder'],
    'on' => [
        '@always' => 'finalizing'
    ]
],

'finalizing' => [
    'entry' => ['sendConfirmation', 'updateInventory', 'logOrder'],
    'on' => [
        '@always' => 'complete'
    ]
]
```

## Machine Refactoring Techniques

### Simplifying Complex Machines

#### Technique 1: Extract Sub-Machines

When a machine becomes too complex, extract related functionality.

```php
// BEFORE: Monolithic machine
class OrderMachine extends MachineDefinition
{
    // 50+ states handling order, payment, shipping, returns, etc.
}

// AFTER: Separated concerns
class OrderMachine extends MachineDefinition
{
    // Handles core order lifecycle
    // Spawns child machines for complex operations
}

class PaymentMachine extends MachineDefinition
{
    // Handles payment processing complexity
}

class ShippingMachine extends MachineDefinition
{
    // Handles shipping and delivery
}
```

#### Technique 2: State Consolidation

Merge states that don't add meaningful distinction.

```php
// BEFORE: Too many similar states
'pending_review_assignment' => [...],
'review_assigned' => [...],
'awaiting_reviewer_acceptance' => [...],
'reviewer_accepted' => [...],

// AFTER: Consolidated
'review_assignment' => [
    'on' => [
        'REVIEWER_ASSIGNED' => 'in_review'
    ]
]
```

### Handling State Explosion

State explosion occurs when you have too many similar states. Here are patterns to manage it:

#### Pattern 1: Context-Driven States

```php
// POOR: State per permission level
'pending_junior_review' => [...],
'pending_senior_review' => [...],
'pending_manager_review' => [...],
'pending_director_review' => [...],

// GOOD: Context-driven
'pending_review' => [
    'on' => [
        'ASSIGN_REVIEWER' => [
            'target' => 'in_review',
            'actions' => 'assignToAppropriateReviewer'
        ]
    ]
]
```

#### Pattern 2: Dynamic State Routing

```php
// Use context to determine transitions
class AssignReviewerAction extends ActionBehavior
{
    public function __invoke(ReviewContext $context, EventDefinition $event): void
    {
        $reviewer = $this->findAppropriateReviewer(
            $context->requestType,
            $context->amount,
            $context->department
        );
        
        $context->assignedReviewer = $reviewer;
        $context->reviewLevel = $reviewer->level;
    }
}
```

## Common Design Problems & Solutions

### Problem 1: The God State

**Symptom:** One state handles too many different events and responsibilities.

```php
// POOR: God state
'processing' => [
    'on' => [
        'PAYMENT_SUCCESS' => 'shipped',
        'PAYMENT_FAILED' => 'payment_failed',  
        'INVENTORY_UNAVAILABLE' => 'backordered',
        'ADDRESS_INVALID' => 'address_verification',
        'FRAUD_DETECTED' => 'fraud_review',
        'DISCOUNT_APPLIED' => 'processing', // stays in same state
        'TAX_CALCULATED' => 'processing',
        'SHIPPING_CALCULATED' => 'processing'
    ]
]
```

**Solution:** Break into focused states.

```php
// GOOD: Focused states
'validating_order' => [
    'on' => [
        'VALIDATION_COMPLETE' => 'processing_payment',
        'ADDRESS_INVALID' => 'address_verification',
        'INVENTORY_UNAVAILABLE' => 'backordered'
    ]
],
'processing_payment' => [
    'on' => [
        'PAYMENT_SUCCESS' => 'preparing_shipment',
        'PAYMENT_FAILED' => 'payment_failed',
        'FRAUD_DETECTED' => 'fraud_review'
    ]
],
'preparing_shipment' => [
    'entry' => ['calculateShipping', 'calculateTax'],
    'on' => [
        '@always' => 'ready_to_ship'
    ]
]
```

### Problem 2: Callback Hell

**Symptom:** Deep nesting of conditional transitions.

```php
// POOR: Nested conditions
'reviewing' => [
    'on' => [
        'REVIEW_COMPLETE' => [
            [
                'target' => 'approved',
                'guards' => ['isApproved', 'isManagerReview', 'amountUnderLimit']
            ],
            [
                'target' => 'needs_senior_approval',
                'guards' => ['isApproved', 'isManagerReview', 'amountOverLimit']
            ],
            [
                'target' => 'needs_hr_review',
                'guards' => ['isApproved', 'isHRRelated']
            ],
            // ... more nested conditions
        ]
    ]
]
```

**Solution:** Use routing states.

```php
// GOOD: Clear routing
'reviewing' => [
    'on' => [
        'REVIEW_COMPLETE' => 'routing_approval'
    ]
],
'routing_approval' => [
    'on' => [
        '@always' => [
            [
                'target' => 'rejected',
                'guards' => 'isRejected'
            ],
            [
                'target' => 'needs_senior_approval',
                'guards' => ['isApproved', 'requiresSeniorApproval']
            ],
            [
                'target' => 'needs_hr_review',
                'guards' => ['isApproved', 'requiresHRReview']
            ],
            [
                'target' => 'approved',
                'guards' => 'isApproved'
            ]
        ]
    ]
]
```

### Problem 3: Context Bloat

**Symptom:** Context grows without bounds, making machines slow and hard to understand.

```php
// POOR: Bloated context
'context' => [
    'userId' => null,
    'userName' => null,
    'userEmail' => null,
    'userDepartment' => null,
    'userManager' => null,
    'orderId' => null,
    'orderItems' => [],
    'orderTotal' => 0,
    'orderTax' => 0,
    'orderShipping' => 0,
    'paymentId' => null,
    'paymentMethod' => null,
    'paymentStatus' => null,
    // ... 50+ more fields
]
```

**Solution:** Contextual sub-objects and lazy loading.

```php
// GOOD: Organized context
'context' => [
    'orderId' => null,
    'status' => 'draft',
    'user' => [
        'id' => null,
        'level' => 'standard'
    ],
    'totals' => [
        'subtotal' => 0,
        'tax' => 0,
        'shipping' => 0,
        'total' => 0
    ],
    'payment' => [
        'id' => null,
        'status' => 'pending'
    ]
]
```

## Advanced Design Patterns

### Pattern 1: State Machine Composition

Combine multiple machines for complex workflows.

```php
class OrderProcessingMachine extends MachineDefinition
{
    // Main orchestration machine
    public static function definition(): array
    {
        return [
            'states' => [
                'payment_processing' => [
                    'invoke' => [
                        'src' => PaymentMachine::class,
                        'onDone' => 'fulfillment',
                        'onError' => 'payment_failed'
                    ]
                ],
                'fulfillment' => [
                    'invoke' => [
                        'src' => FulfillmentMachine::class,
                        'onDone' => 'complete',
                        'onError' => 'fulfillment_failed'
                    ]
                ]
            ]
        ];
    }
}
```

### Pattern 2: Event-Driven State Selection

Let events determine state transitions dynamically.

```php
class DynamicWorkflowAction extends ActionBehavior
{
    public function __invoke(WorkflowContext $context, EventDefinition $event): void
    {
        $nextState = $this->workflowEngine->determineNextState(
            $context->workflowType,
            $context->currentStep,
            $event->payload
        );
        
        // Set next state in context for dynamic routing
        $context->nextState = $nextState;
    }
}
```

### Pattern 3: Conditional Machine Loading

Load different machine definitions based on context.

```php
class OrderMachine extends MachineDefinition
{
    public static function definition(): array
    {
        return match (config('features.advanced_workflow')) {
            true => self::advancedDefinition(),
            false => self::basicDefinition()
        };
    }
    
    private static function advancedDefinition(): array
    {
        // Complex machine with all features
    }
    
    private static function basicDefinition(): array
    {
        // Simplified machine for basic use cases
    }
}
```

## Performance Optimization

### Database Query Optimization

#### Problem: N+1 Queries in Actions

```php
// POOR: Multiple queries per action
class NotifyStakeholdersAction extends ActionBehavior
{
    public function __invoke(ProjectContext $context): void
    {
        foreach ($context->stakeholderIds as $stakeholderId) {
            $stakeholder = User::find($stakeholderId); // N+1 query
            Mail::to($stakeholder->email)->send(new UpdateNotification());
        }
    }
}
```

#### Solution: Batch Loading

```php
// GOOD: Single query with batch processing
class NotifyStakeholdersAction extends ActionBehavior
{
    public function __invoke(ProjectContext $context): void
    {
        $stakeholders = User::whereIn('id', $context->stakeholderIds)->get();
        
        $notifications = $stakeholders->map(fn($user) => 
            new UpdateNotification($user->email)
        );
        
        Mail::send($notifications);
    }
}
```

### Context Size Management

Keep context lean by storing only essential data.

```php
// POOR: Storing full objects
'context' => [
    'user' => $user->toArray(), // Full user object
    'order' => $order->toArray(), // Full order with relations
]

// GOOD: Store only IDs and essential data
'context' => [
    'userId' => $user->id,
    'orderId' => $order->id,
    'orderStatus' => $order->status,
    'orderTotal' => $order->total
]
```

### Event Frequency Management

For high-frequency events, consider batching.

```php
// High-frequency events can be batched
class BatchedUpdateAction extends ActionBehavior
{
    public function __invoke(AnalyticsContext $context, EventDefinition $event): void
    {
        // Batch updates instead of individual database hits
        $this->batchQueue->add($event->payload);
        
        if ($this->batchQueue->size() >= 100) {
            $this->batchQueue->flush();
        }
    }
}
```

## Testing-Friendly Design

### Design for Testability

Structure machines to make testing easy and fast.

#### Testable Action Design

```php
// GOOD: Action with clear dependencies
class ProcessOrderAction extends ActionBehavior
{
    public function __construct(
        private PaymentService $paymentService,
        private InventoryService $inventoryService,
        private NotificationService $notificationService
    ) {}
    
    public function __invoke(OrderContext $context, EventDefinition $event): void
    {
        $paymentResult = $this->paymentService->process($context->paymentData);
        
        if ($paymentResult->successful()) {
            $this->inventoryService->reserve($context->items);
            $this->notificationService->sendConfirmation($context->customerEmail);
            
            $context->paymentId = $paymentResult->id;
            $context->status = 'confirmed';
        }
    }
}
```

#### Test Structure

```php
public function test_order_processing_with_successful_payment()
{
    // Arrange
    $mockPaymentService = Mockery::mock(PaymentService::class);
    $mockPaymentService->shouldReceive('process')
        ->andReturn(new PaymentResult(true, 'payment_123'));
        
    // Act
    $machine = OrderMachine::create(['orderId' => 123]);
    $machine = $machine->send('PROCESS_ORDER', ['amount' => 99.99]);
    
    // Assert  
    $this->assertEquals('confirmed', $machine->state->value);
    $this->assertEquals('payment_123', $machine->context->paymentId);
}
```

### Machine Design for Testing

Create machines that are easy to test by designing clear state boundaries.

```php
// GOOD: Clear state boundaries make testing easy
public function test_approval_workflow()
{
    $machine = ApprovalMachine::create();
    
    // Test each state transition independently
    $this->assertEquals('draft', $machine->state->value);
    
    $machine = $machine->send('SUBMIT');
    $this->assertEquals('pending_review', $machine->state->value);
    
    $machine = $machine->send('APPROVE');
    $this->assertEquals('approved', $machine->state->value);
}
```

## Documentation and Maintenance

### Self-Documenting Machines

Design machines that are self-explanatory.

```php
// GOOD: Clear, self-documenting structure
'states' => [
    'awaiting_customer_payment' => [
        'on' => [
            'PAYMENT_RECEIVED' => [
                'target' => 'processing_order',
                'actions' => 'validatePaymentAndStartProcessing'
            ],
            'PAYMENT_EXPIRED' => [
                'target' => 'payment_expired',
                'actions' => 'notifyCustomerOfExpiration'
            ]
        ]
    ]
]
```

### Evolution-Friendly Design

Design machines that can evolve without breaking existing functionality.

```php
// GOOD: Extensible design
'states' => [
    'processing' => [
        'on' => [
            'PROCESS_COMPLETE' => 'routing_next_step'
        ]
    ],
    'routing_next_step' => [
        'on' => [
            '@always' => [
                // Easy to add new routing logic here
                ['target' => 'quality_check', 'guards' => 'requiresQualityCheck'],
                ['target' => 'packaging', 'guards' => 'readyForPackaging'],
                ['target' => 'complete']
            ]
        ]
    ]
]
```

## Best Practices Summary

### Do's

1. **Start Simple**: Begin with basic states and evolve
2. **Extract Patterns**: Look for repeated guard/action combinations
3. **Use Intermediate States**: For complex validation or routing logic
4. **Design for Testing**: Clear state boundaries and mockable dependencies
5. **Document Intent**: Use clear state and event names
6. **Optimize Context**: Store only essential data
7. **Plan for Evolution**: Design extensible state structures

### Don'ts

1. **Don't Over-Engineer**: Avoid premature optimization
2. **Don't Create God States**: Keep states focused
3. **Don't Ignore Performance**: Consider database and memory impact
4. **Don't Skip Testing**: Test state transitions thoroughly
5. **Don't Hardcode Values**: Use context and configuration
6. **Don't Forget Error States**: Plan for failure scenarios
7. **Don't Neglect Documentation**: Future you will thank you

## Real-World Example: Evolution of an Order Machine

### Version 1: Naive Implementation

```php
// Initial version - works but not optimal
'states' => [
    'new' => [
        'on' => [
            'VALIDATE' => [
                'target' => 'validated',
                'guards' => ['hasItems', 'hasPayment', 'hasAddress'],
                'actions' => ['validatePayment', 'checkInventory', 'calculateTax']
            ]
        ]
    ],
    'validated' => [
        'on' => [
            'PROCESS' => [
                'target' => 'processed',
                'actions' => ['chargePayment', 'reserveInventory', 'createShipment']
            ]
        ]
    ]
]
```

### Version 2: Recognizing Patterns

```php
// After identifying repeated validation patterns
'states' => [
    'new' => [
        'on' => ['SUBMIT' => 'validating']
    ],
    'validating' => [
        'entry' => 'validateOrder',
        'on' => [
            '@always' => [
                ['target' => 'processing', 'guards' => 'validationPassed'],
                ['target' => 'validation_failed']
            ]
        ]
    ],
    'processing' => [
        'entry' => ['processPayment', 'reserveInventory'],
        'on' => [
            '@always' => 'complete'
        ]
    ]
]
```

### Version 3: Mature Design

```php
// Final version - optimized and maintainable
'states' => [
    'draft' => [
        'on' => ['SUBMIT' => 'validating']
    ],
    'validating' => [
        'type' => 'parallel',
        'states' => [
            'payment_validation' => [
                'initial' => 'checking',
                'states' => [
                    'checking' => [
                        'entry' => 'validatePayment',
                        'on' => [
                            '@always' => [
                                ['target' => 'valid', 'guards' => 'paymentValid'],
                                ['target' => 'invalid']
                            ]
                        ]
                    ],
                    'valid' => {'type' => 'final'},
                    'invalid' => {'type' => 'final'}
                ]
            ],
            'inventory_validation' => [
                // Similar structure for inventory
            ]
        ],
        'onDone' => [
            ['target' => 'processing', 'guards' => 'allValidationsPass'],
            ['target' => 'validation_failed']
        ]
    ],
    'processing' => [
        'entry' => 'startProcessing',
        'invoke' => [
            'src' => PaymentProcessingMachine::class,
            'onDone' => 'fulfillment',
            'onError' => 'processing_failed'
        ]
    ]
]
```

This evolution shows the journey from basic functionality to sophisticated, maintainable machine design.

## Next Steps

Machine design expertise develops over time. To accelerate your learning:

- **[Event Storming](./event-storming.md)** - Discover optimal machine boundaries
- **[States and Transitions](./states-and-transitions.md)** - Master the building blocks
- **[Context Management](./context.md)** - Optimize data handling
- **[Testing Strategies](../testing/strategies.md)** - Validate your designs
- **[Advanced Features](../advanced/)** - Explore sophisticated patterns

Remember: great machine design is less about following rules and more about developing intuition for what makes machines maintainable, performant, and joy to work with. Practice with real projects, learn from code reviews, and don't be afraid to refactor as you gain experience.