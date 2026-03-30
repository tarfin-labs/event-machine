# Exceptions

EventMachine throws specific exceptions for different error conditions. This page documents all exceptions, their causes, and how to handle them.

## Typed Contract Exceptions

These exceptions relate to the typed inter-machine contract system (`MachineInput`, `MachineOutput`, `MachineFailure`).

### MachineInputValidationException

**Thrown when:** A `MachineInput` class cannot be constructed from the parent context. This happens when required constructor parameters are missing from the parent's context or have incompatible types.

**HTTP status:** 422

**When it fires:** During child machine creation (sync) or inside `ChildMachineJob` (async). In async mode, this triggers `@fail` on the parent.

```php ignore
// Parent context has 'orderId' but not 'amount'
'processing_payment' => [
    'machine' => PaymentMachine::class,
    'input'   => PaymentInput::class,  // requires orderId + amount
    '@done'   => 'completed',
    '@fail'   => 'payment_failed',     // MachineInputValidationException routes here
],
```

**Fix:** Ensure the parent context contains all keys required by the `MachineInput` constructor before entering the delegation state.

### MachineOutputResolutionException

**Thrown when:** A final state's `output` key references a `MachineOutput` class that cannot be resolved. This includes cases where the output class does not exist, is not a valid `MachineOutput` subclass, or the context does not contain the required constructor parameters.

**When it fires:** When `$machine->output()` is called or when `ChildMachineCompletionJob` resolves the child's output for the parent.

**Fix:** Verify the `MachineOutput` class exists, extends `MachineOutput`, and that the machine's context contains all required constructor parameters at final state entry.

### MachineOutputInjectionException

**Thrown when:** An `OutputBehavior` type-hints a `MachineOutput` subclass in its `__invoke()` method, but the child machine does not produce a matching output. This typically occurs in forwarded endpoint outputs that expect a child's typed output.

**When it fires:** During output behavior parameter resolution in forwarded endpoints.

```php ignore
// This output expects PaymentOutput from the child
class CardSubmittedOutput extends OutputBehavior
{
    public function __invoke(ContextManager $context, PaymentOutput $childOutput): array
    {
        // If PaymentMachine doesn't define PaymentOutput, throws MachineOutputInjectionException
    }
}
```

**Fix:** Ensure the child machine's final state defines an `output` that produces the expected `MachineOutput` type.

### MachineFailureResolutionException

**Thrown when:** A delegation state's `failure` key references a `MachineFailure` class that cannot be resolved. This includes cases where the class does not exist or is not a valid `MachineFailure` subclass.

**When it fires:** During machine definition validation or when `@fail` attempts to construct the failure instance.

**Fix:** Verify the `MachineFailure` class exists and extends `MachineFailure`.
