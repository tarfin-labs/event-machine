# Events and Actions

Events trigger state transitions, while actions perform side effects during those transitions. Together, they form the dynamic behavior of your state machine.

## Events

**Events** are signals that can trigger state transitions. They carry information (payload) and tell the machine "something happened."

### Event Types

#### 1. String Events

The simplest form - just a string identifier:

```php
'on' => [
    'START' => 'running',
    'STOP' => 'stopped',
    'RESET' => 'idle'
]
```

#### 2. Event Classes

More sophisticated events with validation and structure:

```php
<?php

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class UserLoginEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'USER_LOGIN';
    }

    public function validatePayload(): array
    {
        return [
            'email' => 'required|email',
            'password' => 'required|min:8'
        ];
    }
}
```

Register the event in your machine:

```php
behavior: [
    'events' => [
        'USER_LOGIN' => UserLoginEvent::class
    ]
]
```

#### 3. Internal Events

Special events that are handled internally:

- `@always` - Fires immediately when entering a state
- `@entry` - Alias for entry actions
- `@exit` - Alias for exit actions

### Sending Events

#### Basic Event Sending

```php
$machine = $machine->send('START');
```

#### Events with Payload

```php
$machine = $machine->send('UPDATE_PROFILE', [
    'name' => 'John Doe',
    'email' => 'john@example.com'
]);
```

#### Multiple Events

```php
$machine = $machine->send(['SAVE', 'NOTIFY']);
```

### Event Validation

EventMachine automatically validates event payloads when using event classes:

```php
class CreateOrderEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'CREATE_ORDER';
    }

    public function validatePayload(): array
    {
        return [
            'customer_id' => 'required|integer|exists:customers,id',
            'items' => 'required|array|min:1',
            'items.*.sku' => 'required|string',
            'items.*.quantity' => 'required|integer|min:1',
            'total' => 'required|numeric|min:0'
        ];
    }

    public function getErrorMessage(): string
    {
        return 'Invalid order data provided';
    }
}
```

If validation fails, a `MachineEventValidationException` is thrown.

## Actions

**Actions** are side effects that occur during state transitions. They can modify context, call external services, or perform any other operations.

### Action Types

#### 1. Inline Functions

Define actions directly in the machine definition:

```php
behavior: [
    'actions' => [
        'incrementCounter' => function (ContextManager $context): void {
            $context->count++;
        },
        'logTransition' => function (ContextManager $context, EventDefinition $event): void {
            Log::info('State transition', [
                'event' => $event->type,
                'payload' => $event->payload
            ]);
        }
    ]
]
```

#### 2. Action Classes

Create dedicated action classes for complex logic:

```php
<?php

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class SendWelcomeEmailAction extends ActionBehavior
{
    public function __invoke(UserContext $context, EventDefinition $event): void
    {
        Mail::to($context->email)->send(new WelcomeEmail($context->name));
        
        $context->welcomeEmailSent = true;
        $context->welcomeEmailSentAt = now();
    }
}
```

#### 3. Service Container Integration

Actions can use Laravel's service container for dependency injection:

```php
class ProcessPaymentAction extends ActionBehavior
{
    public function __construct(
        private PaymentService $paymentService,
        private NotificationService $notificationService
    ) {}

    public function __invoke(OrderContext $context, EventDefinition $event): void
    {
        $result = $this->paymentService->charge(
            $context->paymentMethod,
            $context->total
        );

        if ($result->successful()) {
            $context->paymentId = $result->id;
            $context->paidAt = now();
            
            $this->notificationService->send(
                $context->customerEmail,
                'Payment Successful'
            );
        } else {
            throw new PaymentFailedException($result->error);
        }
    }
}
```

### Action Execution Context

Actions receive different parameters based on their needs:

```php
// Just context
function (ContextManager $context): void { }

// Context and event
function (ContextManager $context, EventDefinition $event): void { }

// Context and state
function (ContextManager $context, StateDefinition $state): void { }

// All three
function (ContextManager $context, EventDefinition $event, StateDefinition $state): void { }

// Custom context type
function (CustomContext $context, EventDefinition $event): void { }
```

### Multiple Actions

Execute multiple actions in sequence:

```php
'on' => [
    'PLACE_ORDER' => [
        'target' => 'processing',
        'actions' => [
            'validateOrder',
            'reserveInventory',
            'processPayment',
            'sendConfirmation'
        ]
    ]
]
```

Actions execute in the order specified. If any action throws an exception, the transition is rolled back.

### Entry and Exit Actions

Actions that run when entering or leaving states:

```php
'states' => [
    'processing' => [
        'entry' => [
            'startProcessing',
            'notifyWarehouse'
        ],
        'exit' => [
            'cleanup',
            'logCompletion'
        ]
    ]
]
```

## Advanced Event Patterns

### Event Aliases

Create shortcuts for commonly used events:

```php
behavior: [
    'events' => [
        'START' => StartProcessingEvent::class,
        'BEGIN' => StartProcessingEvent::class, // Alias
        'INIT' => StartProcessingEvent::class   // Another alias
    ]
]
```

### Conditional Events

Events that only fire under certain conditions:

```php
'on' => [
    'PROCESS' => [
        [
            'target' => 'premium_processing',
            'guards' => 'isPremiumCustomer',
            'actions' => 'processPremium'
        ],
        [
            'target' => 'standard_processing',
            'actions' => 'processStandard'
        ]
    ]
]
```

### Delayed Events

Schedule events to fire later:

```php
class ScheduleReminderAction extends ActionBehavior
{
    public function __invoke(AppointmentContext $context): void
    {
        dispatch(new SendReminderJob($context->appointmentId))
            ->delay($context->reminderTime);
    }
}
```

## Advanced Action Patterns

### Action Chains

Chain actions together with shared data:

```php
class ProcessOrderAction extends ActionBehavior
{
    public function __invoke(OrderContext $context, EventDefinition $event): void
    {
        // Process the order
        $orderId = $this->createOrder($context);
        
        // Make order ID available to subsequent actions
        $event->payload['orderId'] = $orderId;
        $context->orderId = $orderId;
    }
}

class SendConfirmationAction extends ActionBehavior
{
    public function __invoke(OrderContext $context, EventDefinition $event): void
    {
        // Use the order ID from previous action
        $this->sendConfirmation($context->orderId);
    }
}
```

### Conditional Actions

Actions that only execute under certain conditions:

```php
behavior: [
    'actions' => [
        'conditionalNotify' => function (UserContext $context): void {
            if ($context->emailNotificationsEnabled) {
                Mail::to($context->email)->send(new UpdateNotification());
            }
        }
    ]
]
```

### Async Actions

Actions can dispatch jobs for background processing:

```php
class ProcessLargeFileAction extends ActionBehavior
{
    public function __invoke(FileContext $context): void
    {
        // Queue background job
        ProcessFileJob::dispatch($context->fileId)
            ->onQueue('file-processing');
            
        $context->processingStarted = true;
        $context->processingStartedAt = now();
    }
}
```

### Error Handling in Actions

Handle errors gracefully:

```php
class ReliableApiCallAction extends ActionBehavior
{
    public function __invoke(ApiContext $context, EventDefinition $event): void
    {
        try {
            $response = Http::timeout(30)->post('/api/endpoint', $event->payload);
            
            if ($response->successful()) {
                $context->apiResponse = $response->json();
                $context->lastApiCall = now();
            } else {
                throw new ApiException('API call failed: ' . $response->status());
            }
        } catch (Exception $e) {
            Log::error('API call failed', [
                'error' => $e->getMessage(),
                'payload' => $event->payload
            ]);
            
            // Re-throw to trigger error handling
            throw $e;
        }
    }
}
```

## Event Sourcing

EventMachine automatically persists all events, providing event sourcing capabilities out of the box:

```php
// All events are automatically stored
$machine = OrderMachine::create(['customerId' => 123]);
$machine = $machine->send('ADD_ITEM', ['sku' => 'ABC123', 'quantity' => 2]);
$machine = $machine->send('APPLY_DISCOUNT', ['code' => 'SAVE10']);
$machine = $machine->send('PLACE_ORDER');

// Restore machine from any point in time
$restoredMachine = OrderMachine::find($machine->id);

// Get event history
$events = MachineEvent::where('machine_id', $machine->id)->get();
foreach ($events as $event) {
    echo "Event: {$event->type} at {$event->created_at}\n";
}
```

## Testing Events and Actions

### Testing Events

```php
public function test_user_can_login_with_valid_credentials()
{
    $machine = AuthMachine::create();
    
    $machine = $machine->send('USER_LOGIN', [
        'email' => 'user@example.com',
        'password' => 'password123'
    ]);
    
    $this->assertEquals('authenticated', $machine->state->value);
}

public function test_invalid_login_payload_throws_validation_error()
{
    $machine = AuthMachine::create();
    
    $this->expectException(MachineEventValidationException::class);
    
    $machine->send('USER_LOGIN', [
        'email' => 'invalid-email',  // Invalid email format
        'password' => '123'          // Too short
    ]);
}
```

### Testing Actions

```php
public function test_welcome_email_is_sent_on_registration()
{
    Mail::fake();
    
    $machine = UserMachine::create();
    $machine = $machine->send('REGISTER', [
        'email' => 'user@example.com',
        'name' => 'John Doe'
    ]);
    
    Mail::assertSent(WelcomeEmail::class, function ($mail) {
        return $mail->hasTo('user@example.com');
    });
}
```

### Faking Actions

Use EventMachine's built-in faking for testing:

```php
public function test_order_processing_without_side_effects()
{
    // Fake all actions
    ProcessPaymentAction::fake();
    SendEmailAction::fake();
    
    $machine = OrderMachine::create();
    $machine = $machine->send('PLACE_ORDER', ['total' => 99.99]);
    
    $this->assertEquals('processing', $machine->state->value);
    
    // Assert actions were called
    ProcessPaymentAction::assertInvoked();
    SendEmailAction::assertInvoked();
}
```

## Best Practices

### 1. **Keep Actions Pure**
Actions should be predictable and testable. Avoid actions that depend on global state.

### 2. **Use Event Classes for Complex Events**
When events need validation or carry complex data, use event classes.

### 3. **Handle Errors Gracefully**
Always consider error cases in actions and handle them appropriately.

### 4. **Separate Concerns**
Use separate actions for different concerns (validation, persistence, notifications).

### 5. **Document Side Effects**
Clearly document what side effects each action produces.

### 6. **Use Dependency Injection**
Leverage Laravel's container for testable, modular actions.

## Next Steps

- [Context Management](./context.md) - Managing data across state changes
- [Guards and Conditions](./guards.md) - Controlling when transitions occur
- [Testing](../testing/) - Comprehensive testing strategies