# Testing Time-Based Events

Test timer transitions using `Carbon::setTestNow()` to control time and the `machine:process-timers` Artisan command to trigger sweeps.

## Testing `after` Timers

<!-- doctest-attr: no_run -->
```php
use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;

it('cancels order after 7 days', function (): void {
    Bus::fake();

    $machine = OrderMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Backdate state entry to 8 days ago
    MachineCurrentState::forInstance($rootEventId)
        ->update(['state_entered_at' => now()->subDays(8)]);

    // Run the sweep
    $this->artisan('machine:process-timers', ['--class' => OrderMachine::class]);

    // Verify: ORDER_EXPIRED was dispatched
    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1);
});
```

## Testing `every` Timers

<!-- doctest-attr: no_run -->
```php
it('sends billing event every 30 days', function (): void {
    Bus::fake();

    $machine = SubscriptionMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Backdate past interval
    MachineCurrentState::forInstance($rootEventId)
        ->update(['state_entered_at' => now()->subDays(31)]);

    $this->artisan('machine:process-timers', ['--class' => SubscriptionMachine::class]);

    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1);
});
```

## Testing `every` with max/then

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Models\MachineTimerFire;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;

it('sends MAX_RETRIES after 3 retries', function (): void {
    Bus::fake();

    $machine = RetryMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    MachineCurrentState::forInstance($rootEventId)
        ->update(['state_entered_at' => now()->subHours(25)]);

    // Simulate 3 previous fires
    MachineTimerFire::create([
        'root_event_id' => $rootEventId,
        'timer_key'     => 'retry.retrying:RETRY:21600',
        'last_fired_at' => now()->subHours(7),
        'fire_count'    => 3,
        'status'        => MachineTimerFire::STATUS_ACTIVE,
    ]);

    $this->artisan('machine:process-timers', ['--class' => RetryMachine::class]);

    // MAX_RETRIES event dispatched, not RETRY
    Bus::assertBatched(fn ($batch) => $batch->jobs->first()->event['type'] === 'MAX_RETRIES');
});
```

## Testing Implicit Cancel

<!-- doctest-attr: no_run -->
```php
it('timer does not fire after leaving state', function (): void {
    Bus::fake();

    $machine = OrderMachine::create();
    $machine->persist();

    // Transition out of the state with the timer
    $machine->send(['type' => 'PAY']);
    $machine->persist();

    // Even with backdate, instance is no longer in awaiting_payment
    $this->artisan('machine:process-timers', ['--class' => OrderMachine::class]);

    Bus::assertNothingBatched();
});
```

## Testing Timer Events Manually

Timer events are just regular events — you can send them manually in tests:

<!-- doctest-attr: no_run -->
```php
it('handles ORDER_EXPIRED event', function (): void {
    $machine = OrderMachine::create();
    $machine->send(['type' => 'ORDER_EXPIRED']);

    expect($machine->state->currentStateDefinition->id)->toBe('order.cancelled');
});
```

## Key Testing Patterns

| Pattern | Approach |
|---------|----------|
| Backdate state entry | `MachineCurrentState::forInstance($id)->update(['state_entered_at' => ...])` |
| Simulate previous fires | Create `MachineTimerFire` records directly |
| Verify dispatch | `Bus::fake()` + `Bus::assertBatched()` |
| Verify no dispatch | `Bus::assertNothingBatched()` |
| Manual event send | `$machine->send(['type' => 'EVENT'])` — no sweep needed |
| Self-loop preservation | Persist without state change, check `state_entered_at` unchanged |
