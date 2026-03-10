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

::: tip Related
See [Overview](/testing/overview) for the testing pyramid
and [TestMachine](/testing/test-machine) for the complete assertion API.
:::
