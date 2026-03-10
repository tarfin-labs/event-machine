# State Assertions

::: tip Moved
State assertions are now built into [TestMachine](/testing/test-machine).
This page covers the underlying API for cases where you need direct access.
:::

## Direct State Access

For advanced cases where TestMachine doesn't fit:

<!-- doctest-attr: ignore -->
```php
// Direct state matching
expect($machine->state->matches('processing'))->toBeTrue();
expect($machine->state->value)->toBe(['order.processing']);

// Context access
expect($machine->state->context->get('total'))->toBe(100);
expect($machine->state->context->has('paid_at'))->toBeTrue();

// History inspection
expect($machine->state->history->pluck('type'))->toContain('SUBMIT');
```

> For fluent assertions, see [TestMachine](/testing/test-machine).
> For isolated testing, see [Isolated Testing](/testing/isolated-testing).
