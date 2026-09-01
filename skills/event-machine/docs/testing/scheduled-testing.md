# Scheduled Events Testing

## Test Helpers

### `runSchedule(string $eventType)`

Sends the scheduled event inline, bypassing the queue. Simulates what `ProcessScheduledCommand` does for a single instance.

<!-- doctest-attr: no_run -->
```php
ScheduledMachine::test()
    ->assertState('active')
    ->runSchedule('CHECK_EXPIRY')
    ->assertState('expired');
```

Throws `AssertionFailedError` if the schedule is not defined on the machine.

### `assertHasSchedule(string $eventType)`

Asserts that the machine definition has a schedule for the given event type.

<!-- doctest-attr: no_run -->
```php
ScheduledMachine::test()
    ->assertHasSchedule('CHECK_EXPIRY')
    ->assertHasSchedule('DAILY_REPORT');
```

## Key Patterns

| What to Test | How |
|-------------|-----|
| Schedule exists | `->assertHasSchedule('EVENT')` |
| Event transitions machine | `->runSchedule('EVENT')->assertState('new_state')` |
| Guards block transition | `->runSchedule('EVENT')->assertState('same_state')` |
| Context updated by action | `->runSchedule('EVENT')->assertContext('key', value)` |
| Nonexistent schedule | `->runSchedule('BAD')` throws |

## Testing Resolvers

Resolvers are plain PHP classes — test them independently:

<!-- doctest-attr: no_run -->
```php
it('resolver returns expired applications', function (): void {
    // Seed test data
    Application::factory()->create([
        'created_at'      => now()->subDays(10),
        'status'          => ApplicationStatus::APPROVED,
        'application_mre' => 'mre-1',
    ]);

    $resolver = new ExpiredApplicationsResolver();
    $ids      = $resolver();

    expect($ids)->toContain('mre-1');
});
```

## Testing with ProcessScheduledCommand

For integration testing, run the actual artisan command with `Bus::fake()`:

<!-- doctest-attr: no_run -->
```php
use Illuminate\Support\Facades\Bus;

it('command dispatches batch for resolved instances', function (): void {
    Bus::fake();

    // Seed machine_current_states
    MachineCurrentState::insert([
        ['root_event_id' => 'mre-1', 'machine_class' => MyMachine::class,
         'state_id' => 'active', 'state_entered_at' => now()],
    ]);

    // Set up resolver
    MyResolver::setUp(['mre-1']);

    $this->artisan('machine:process-scheduled', [
        '--class' => MyMachine::class,
        '--event' => 'CHECK_EXPIRY',
    ])->assertSuccessful();

    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1);
});
```

## E2E Testing

For full pipeline verification without `Bus::fake()`:

<!-- doctest-attr: no_run -->
```php
it('E2E: schedule pipeline transitions machine', function (): void {
    $machine = MyMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    MyResolver::setUp([$rootEventId]);

    $this->artisan('machine:process-scheduled', [
        '--class' => MyMachine::class,
        '--event' => 'CHECK_EXPIRY',
    ])->assertExitCode(0);

    $restored = MyMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('my_machine.expired');
});
```
