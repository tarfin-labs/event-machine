# Exceptions API

Reference for all EventMachine exceptions.

## Exception Hierarchy

```
Exception
└── Tarfinlabs\EventMachine\Exceptions\
    ├── BehaviorNotFoundException
    ├── InvalidFinalStateDefinitionException
    ├── MachineAlreadyRunningException
    ├── MachineContextValidationException
    ├── MachineDefinitionNotFoundException
    ├── MachineEventValidationException
    ├── MachineValidationException
    ├── MissingMachineContextException
    ├── NoStateDefinitionFoundException
    ├── NoTransitionDefinitionFoundException
    └── RestoringStateException
```

---

## BehaviorNotFoundException

Thrown when a referenced behavior cannot be found.

```php
namespace Tarfinlabs\EventMachine\Exceptions;

class BehaviorNotFoundException extends Exception
```

### When Thrown

- Action, guard, calculator, or event class/name not registered
- Behavior not found in machine's behavior array

### Example

```php
// This will throw if 'unknownAction' is not registered
MachineDefinition::define(
    config: [
        'states' => [
            'idle' => [
                'on' => [
                    'SUBMIT' => [
                        'actions' => 'unknownAction',  // Not found!
                    ],
                ],
            ],
        ],
    ],
);
```

### Handling

```php
try {
    $machine->send(['type' => 'SUBMIT']);
} catch (BehaviorNotFoundException $e) {
    logger()->error("Missing behavior: {$e->getMessage()}");
}
```

---

## InvalidFinalStateDefinitionException

Thrown when a final state is misconfigured.

```php
namespace Tarfinlabs\EventMachine\Exceptions;

class InvalidFinalStateDefinitionException extends Exception
```

### When Thrown

- Final state has outgoing transitions
- Final state has child states

### Static Methods

#### noTransitions()

```php
public static function noTransitions(string $stateId): self
```

#### noChildStates()

```php
public static function noChildStates(string $stateId): self
```

### Example

```php
// This will throw - final states cannot have transitions
MachineDefinition::define(
    config: [
        'states' => [
            'completed' => [
                'type' => 'final',
                'on' => [
                    'REOPEN' => 'pending',  // Invalid!
                ],
            ],
        ],
    ],
);
```

---

## MachineAlreadyRunningException

Thrown when attempting concurrent access to a locked machine.

```php
namespace Tarfinlabs\EventMachine\Exceptions;

class MachineAlreadyRunningException extends Exception
```

### When Thrown

- Another process is currently sending an event to this machine
- Machine is locked via distributed cache lock

### Static Methods

#### build()

```php
public static function build(string $rootEventId): self
```

### Example

```php
try {
    $machine = OrderMachine::create(state: $rootId);
    $machine->send(['type' => 'UPDATE']);
} catch (MachineAlreadyRunningException $e) {
    // Wait and retry, or return busy response
    return response()->json([
        'error' => 'Machine is currently processing another request',
    ], 423);
}
```

---

## MachineContextValidationException

Thrown when context validation fails.

```php
namespace Tarfinlabs\EventMachine\Exceptions;

class MachineContextValidationException extends ValidationException
```

### When Thrown

- Context class validation rules fail
- Invalid data type for context property
- Required context property missing

### Example

```php
class OrderContext extends ContextManager
{
    public function __construct(
        #[Min(0)]
        public int $amount,
    ) {
        parent::__construct();
    }
}

// This will throw - amount cannot be negative
$context = OrderContext::validateAndCreate(['amount' => -100]);
```

### Handling

```php
try {
    $machine->send([
        'type' => 'SET_AMOUNT',
        'payload' => ['amount' => -100],
    ]);
} catch (MachineContextValidationException $e) {
    $errors = $e->validator->errors()->all();
    // ['The amount must be at least 0.']
}
```

---

## MachineDefinitionNotFoundException

Thrown when machine definition is not available.

```php
namespace Tarfinlabs\EventMachine\Exceptions;

class MachineDefinitionNotFoundException extends Exception
```

### When Thrown

- `Machine::create()` called without definition
- `definition()` method not overridden in subclass

### Static Methods

#### build()

```php
public static function build(): self
```

### Example

```php
// This will throw - definition() not overridden
class BadMachine extends Machine
{
    // Missing definition() method!
}

BadMachine::create();  // Throws!
```

---

## MachineEventValidationException

Thrown when event validation fails.

```php
namespace Tarfinlabs\EventMachine\Exceptions;

class MachineEventValidationException extends ValidationException
```

### When Thrown

- Event payload doesn't match validation rules
- Required payload field missing
- Invalid payload data type

### Example

```php
class SubmitEvent extends EventBehavior
{
    public function validatePayload(): ?array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0'],
        ];
    }
}

// This will throw - amount is required
$machine->send([
    'type' => 'SUBMIT',
    'payload' => [],  // Missing amount!
]);
```

---

## MachineValidationException

Thrown when a ValidationGuardBehavior fails.

```php
namespace Tarfinlabs\EventMachine\Exceptions;

class MachineValidationException extends Exception
```

### When Thrown

- ValidationGuardBehavior returns false
- Contains error message from failed guard

### Static Methods

#### withMessages()

```php
public static function withMessages(array $messages): self
```

### Properties

Access error messages:

```php
$exception->getMessage();
$exception->getMessages();  // Array of all errors
```

### Example

```php
class AmountPositiveGuard extends ValidationGuardBehavior
{
    public ?string $errorMessage = 'Amount must be positive';

    public function __invoke(ContextManager $context): bool
    {
        return $context->amount > 0;
    }
}

// When guard fails:
try {
    $machine->send(['type' => 'SUBMIT']);
} catch (MachineValidationException $e) {
    echo $e->getMessage();
    // "Amount must be positive"
}
```

---

## MissingMachineContextException

Thrown when required context is missing.

```php
namespace Tarfinlabs\EventMachine\Exceptions;

class MissingMachineContextException extends Exception
```

### When Thrown

- Behavior's `$requiredContext` not satisfied
- Context key missing or wrong type

### Static Methods

#### build()

```php
public static function build(string $contextKey): self
```

### Example

```php
class ProcessAction extends ActionBehavior
{
    public static array $requiredContext = [
        'orderId' => 'string',
        'total' => 'integer',
    ];

    public function __invoke(ContextManager $context): void
    {
        // ...
    }
}

// If context is missing 'orderId':
// Throws: "Missing required context: orderId"
```

---

## NoStateDefinitionFoundException

Thrown when a referenced state doesn't exist.

```php
namespace Tarfinlabs\EventMachine\Exceptions;

class NoStateDefinitionFoundException extends Exception
```

### When Thrown

- Transition targets non-existent state
- State ID not found in machine's idMap

---

## NoTransitionDefinitionFoundException

Thrown when no valid transition exists for an event.

```php
namespace Tarfinlabs\EventMachine\Exceptions;

class NoTransitionDefinitionFoundException extends Exception
```

### When Thrown

- Event type not handled in current state
- No matching transition found

### Static Methods

#### build()

```php
public static function build(string $eventType, string $stateId): self
```

### Example

```php
// Machine in 'pending' state, which only handles 'SUBMIT'
$machine->send(['type' => 'COMPLETE']);
// Throws: No transition for 'COMPLETE' in state 'pending'
```

---

## RestoringStateException

Thrown when state restoration fails.

```php
namespace Tarfinlabs\EventMachine\Exceptions;

class RestoringStateException extends Exception
```

### When Thrown

- Root event ID not found in database
- Events not found in archive
- Corrupted event data

### Static Methods

#### build()

```php
public static function build(string $message): self
```

### Example

```php
try {
    $machine = OrderMachine::create(state: 'invalid-id');
} catch (RestoringStateException $e) {
    // Handle missing machine state
    return redirect()->route('orders.new');
}
```

---

## Best Practices

### Catching Specific Exceptions

```php
try {
    $machine->send(['type' => 'SUBMIT']);
} catch (MachineValidationException $e) {
    // User input error - show validation message
    return back()->withErrors($e->getMessages());
} catch (MachineAlreadyRunningException $e) {
    // Concurrent access - retry later
    return response('Please try again', 423);
} catch (BehaviorNotFoundException $e) {
    // Configuration error - log and alert
    logger()->critical("Missing behavior", ['error' => $e->getMessage()]);
    throw $e;
}
```

### Custom Exception Handling

```php
// In exception handler
public function render($request, Throwable $e)
{
    if ($e instanceof MachineValidationException) {
        return response()->json([
            'message' => 'Validation failed',
            'errors' => $e->getMessages(),
        ], 422);
    }

    if ($e instanceof MachineAlreadyRunningException) {
        return response()->json([
            'message' => 'Resource is busy',
        ], 423);
    }

    return parent::render($request, $e);
}
```

## Related

- [ValidationGuards](/behaviors/validation-guards) - Validation patterns
- [Guards](/behaviors/guards) - Guard behaviors
- [Testing](/testing/overview) - Testing exceptions
