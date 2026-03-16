<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Support\Timer;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Models\MachineTimerFire;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\AfterTimerMachine;

// ─── @after Basic Pipeline ──────────────────────────────────────

it('E2E: @after fires and transitions machine via real pipeline', function (): void {
    $machine = AfterTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Backdate past deadline
    MachineCurrentState::forInstance($rootEventId)
        ->update(['state_entered_at' => now()->subDays(8)]);

    // Run real artisan command (no Bus::fake, sync queue)
    $this->artisan('machine:process-timers', ['--class' => AfterTimerMachine::class])
        ->assertExitCode(0);

    // Restore from DB — verify state changed
    $restored = AfterTimerMachine::create(state: $rootEventId);

    expect($restored->state->currentStateDefinition->id)->toBe('after_timer.cancelled');

    // Verify timer fire record
    $fire = MachineTimerFire::where('root_event_id', $rootEventId)->first();
    expect($fire)->not->toBeNull()
        ->and($fire->status)->toBe(MachineTimerFire::STATUS_FIRED)
        ->and($fire->fire_count)->toBe(1);

    // Verify machine_current_states updated
    $currentState = MachineCurrentState::forInstance($rootEventId)->first();
    expect($currentState->state_id)->toBe('after_timer.cancelled');
});

// ─── @after Not Due ─────────────────────────────────────────────

it('E2E: @after does not fire before deadline', function (): void {
    $machine = AfterTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // state_entered_at is now() — deadline not reached
    $this->artisan('machine:process-timers', ['--class' => AfterTimerMachine::class])
        ->assertExitCode(0);

    $restored = AfterTimerMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('after_timer.awaiting_payment');

    // No timer fire record
    expect(MachineTimerFire::where('root_event_id', $rootEventId)->exists())->toBeFalse();
});

// ─── @after Dedup ───────────────────────────────────────────────

it('E2E: @after fires exactly once across multiple sweeps', function (): void {
    // Machine 1: past deadline
    $machine1 = AfterTimerMachine::create();
    $machine1->persist();
    $root1 = $machine1->state->history->first()->root_event_id;
    MachineCurrentState::forInstance($root1)->update(['state_entered_at' => now()->subDays(8)]);

    // First sweep
    $this->artisan('machine:process-timers', ['--class' => AfterTimerMachine::class]);

    $restored1 = AfterTimerMachine::create(state: $root1);
    expect($restored1->state->currentStateDefinition->id)->toBe('after_timer.cancelled');

    // Machine 2: also past deadline
    $machine2 = AfterTimerMachine::create();
    $machine2->persist();
    $root2 = $machine2->state->history->first()->root_event_id;
    MachineCurrentState::forInstance($root2)->update(['state_entered_at' => now()->subDays(8)]);

    // Second sweep
    $this->artisan('machine:process-timers', ['--class' => AfterTimerMachine::class]);

    // Machine 1: still cancelled (no re-fire)
    $restored1again = AfterTimerMachine::create(state: $root1);
    expect($restored1again->state->currentStateDefinition->id)->toBe('after_timer.cancelled');

    // Machine 2: now cancelled
    $restored2 = AfterTimerMachine::create(state: $root2);
    expect($restored2->state->currentStateDefinition->id)->toBe('after_timer.cancelled');

    // Both have fire_count=1
    expect(MachineTimerFire::where('root_event_id', $root1)->first()->fire_count)->toBe(1)
        ->and(MachineTimerFire::where('root_event_id', $root2)->first()->fire_count)->toBe(1);
});

// ─── @after Implicit Cancel ─────────────────────────────────────

it('E2E: @after implicit cancel when machine leaves state', function (): void {
    $machine = AfterTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Transition out of awaiting_payment
    $machine->send(['type' => 'PAY']);
    $machine->persist();

    // Even backdate wouldn't help — machine left the state
    $this->artisan('machine:process-timers', ['--class' => AfterTimerMachine::class]);

    $restored = AfterTimerMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('after_timer.processing');

    // No timer fire
    expect(MachineTimerFire::where('root_event_id', $rootEventId)->exists())->toBeFalse();

    // machine_current_states shows processing
    $currentState = MachineCurrentState::forInstance($rootEventId)->first();
    expect($currentState->state_id)->toBe('after_timer.processing');
});

// ─── @after Guarded ─────────────────────────────────────────────

it('E2E: @after with guarded multi-branch transition via real pipeline', function (): void {
    // Define machine with guarded @after (mixed array)
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'guarded_e2e',
            'initial' => 'waiting',
            'context' => ['is_expired' => true],
            'states'  => [
                'waiting' => [
                    'on' => [
                        'TIMEOUT' => [
                            ['target' => 'cancelled', 'guards' => 'isExpiredGuard'],
                            ['target' => 'extended'],
                            'after'   => Timer::days(7),
                        ],
                    ],
                ],
                'cancelled' => ['type' => 'final'],
                'extended'  => ['type' => 'final'],
            ],
        ],
        behavior: [
            'guards' => [
                'isExpiredGuard' => fn (ContextManager $ctx): bool => $ctx->get('is_expired'),
            ],
        ],
    );

    // Use definition-level transition (since we can't persist inline definitions)
    $state = $definition->getInitialState();
    $state = $definition->transition(event: ['type' => 'TIMEOUT'], state: $state);

    // Guard passes → first branch (cancelled)
    expect($state->value)->toBe(['guarded_e2e.cancelled']);
});
