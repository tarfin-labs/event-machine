# Testing Parallel States

Strategies for testing machines with parallel (orthogonal) regions.

## Three Strategies

| Strategy | Use Case | Fakes Work? | Needs Queue? |
|----------|---------|-------------|--------------|
| `Queue::fake()` | Verify dispatch (jobs, count, params) | N/A (jobs not executed) | No |
| `config(['queue.default' => 'sync'])` | E2E parallel execution | Yes (same process) | No |
| `withoutParallelDispatch()` | Sequential — no queue infra | Yes (same process) | No |

## Strategy 1: Dispatch Verification

Use Laravel's `Queue::fake()` to verify that parallel region jobs are dispatched correctly without actually executing them:

<!-- doctest-attr: ignore -->
```php
it('dispatches correct parallel jobs', function () {
    Queue::fake();

    $machine = ParallelMachine::create();
    $machine->send(['type' => 'START_PROCESSING']);

    Queue::assertPushed(ProcessParallelRegionJob::class, 2);
    Queue::assertPushed(ProcessParallelRegionJob::class, fn ($job) =>
        str_contains($job->regionId, 'payment')
    );
    Queue::assertPushed(ProcessParallelRegionJob::class, fn ($job) =>
        str_contains($job->regionId, 'inventory')
    );
});
```

## Strategy 2: Sync Queue — E2E

<!-- doctest-attr: ignore -->
```php
it('runs full parallel flow', function () {
    config(['queue.default' => 'sync']);

    $machine = ParallelMachine::create();
    $machine->send(['type' => 'START']);

    expect($machine->state->value)->toContain('processing.payment.charged');
    expect($machine->state->value)->toContain('processing.inventory.reserved');
});
```

## Strategy 3: Sequential — withoutParallelDispatch()

<!-- doctest-attr: ignore -->
```php
it('enters parallel regions sequentially', function () {
    ParallelMachine::test()
        ->withoutParallelDispatch()
        ->send('START')
        ->assertRegionState('payment', 'pending')
        ->assertRegionState('inventory', 'pending');
});
```

## Testing Regions Independently

Extract a single region and test it as a standalone machine:

<!-- doctest-attr: ignore -->
```php
it('tests payment region in isolation', function () {
    $definition = ParallelMachine::definition();
    $regionConfig = $definition->idMap['machine.processing.payment']->config;

    TestMachine::define(
        config: [
            'id' => 'payment_region',
            'initial' => 'pending',
            'context' => ['amount' => 100],
            'states' => $regionConfig['states'],
        ],
        behavior: $definition->behavior,
    )
    ->send('CHARGE')
    ->assertState('charging');
});
```

## Region State Assertions

<!-- doctest-attr: ignore -->
```php
ParallelMachine::test()
    ->send('START')
    ->assertRegionState('payment', 'pending')
    ->assertRegionState('inventory', 'checking');
```

## Completion Assertions

Verify that all regions reached their final states (i.e., the `@done` transition fired):

<!-- doctest-attr: ignore -->
```php
ParallelMachine::test()
    ->withoutParallelDispatch()
    ->send('PAYMENT_SUCCESS')
    ->send('INVENTORY_RESERVE')
    ->assertAllRegionsCompleted()   // any parallel state's @done
    ->assertState('fulfilled');

// With explicit parallel state route
ParallelMachine::test()
    ->withoutParallelDispatch()
    ->send('PAYMENT_SUCCESS')
    ->send('INVENTORY_RESERVE')
    ->assertAllRegionsCompleted('processing');  // specific parallel state
```

## Failure Path Assertions

Test the `@fail` path when a parallel region reaches a failure final state:

<!-- doctest-attr: ignore -->
```php
it('transitions to @fail target when region fails', function () {
    ParallelMachine::test()
        ->withoutParallelDispatch()
        ->send('PAYMENT_FAIL')           // payment region → payment_failed (final)
        ->send('INVENTORY_RESERVE')      // inventory region → reserved (final)
        ->assertState('failed');         // @fail target — not @done
});

it('handles mixed success/failure across regions', function () {
    ParallelMachine::test()
        ->withoutParallelDispatch()
        ->send('PAYMENT_FAIL')
        ->assertRegionState('payment', 'payment_failed')
        ->assertRegionState('inventory', 'checking');  // still in progress
});
```

::: info @fail vs @done
`@fail` fires when the parallel dispatch job fails (e.g., exhausts retries). Individual regions reaching a "failure" final state still trigger `@done` because all regions are in final states. Use guards or context flags to distinguish success from failure in the `@done` handler.
:::

When not all regions have completed, the assertion fails:

<!-- doctest-attr: ignore -->
```php
// Only payment completed — inventory still in 'checking'
ParallelMachine::test()
    ->withoutParallelDispatch()
    ->send('PAYMENT_SUCCESS')
    ->assertAllRegionsCompleted();  // fails — inventory not final
```

::: tip Related
See [Overview](/testing/overview) for the testing pyramid,
[TestMachine](/testing/test-machine) for the complete assertion API,
and [Recipes](/testing/recipes) for common real-world patterns.
:::
