<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Support\Timer;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Models\MachineTimerFire;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\AfterTimerMachine;

// ─── Timer + Delegation ─────────────────────────────────────────

it('E2E: timer event sent manually transitions machine (external trigger)', function (): void {
    $machine = AfterTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // No backdate, no sweep — just send the event directly
    $machine->send(['type' => 'ORDER_EXPIRED']);
    $machine->persist();

    // Verify from DB
    $restored = AfterTimerMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('after_timer.cancelled');

    // No timer fire record (manual send, not sweep)
    expect(MachineTimerFire::where('root_event_id', $rootEventId)->exists())->toBeFalse();
});

// ─── @after + @every Coexist ────────────────────────────────────

it('E2E: @after and @every coexist on same state', function (): void {
    // Define machine with both timer types
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'coexist_e2e',
            'initial' => 'active',
            'context' => ['heartbeat_count' => 0],
            'states'  => [
                'active' => [
                    'on' => [
                        'HEARTBEAT' => ['actions' => 'heartbeatAction', 'every' => Timer::hours(1)],
                        'EXPIRED'   => ['target' => 'done', 'after' => Timer::days(7)],
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
            'actions' => [
                'heartbeatAction' => function (ContextManager $ctx): void {
                    $ctx->set('heartbeat_count', $ctx->get('heartbeat_count') + 1);
                },
            ],
        ],
    );

    // Test via definition (no persistence for inline definitions)
    $state = $definition->getInitialState();

    // Send HEARTBEAT — should run action, stay in active
    $state = $definition->transition(event: ['type' => 'HEARTBEAT'], state: $state);
    expect($state->value)->toBe(['coexist_e2e.active'])
        ->and($state->context->get('heartbeat_count'))->toBe(1);

    // Send EXPIRED — should transition to done
    $state = $definition->transition(event: ['type' => 'EXPIRED'], state: $state);
    expect($state->value)->toBe(['coexist_e2e.done']);

    // Verify both timer definitions exist on the state
    $activeState = $definition->idMap['coexist_e2e.active'];
    $heartbeat   = $activeState->transitionDefinitions['HEARTBEAT'];
    $expired     = $activeState->transitionDefinitions['EXPIRED'];

    expect($heartbeat->timerDefinition)->not->toBeNull()
        ->and($heartbeat->timerDefinition->isEvery())->toBeTrue()
        ->and($expired->timerDefinition)->not->toBeNull()
        ->and($expired->timerDefinition->isAfter())->toBeTrue();
});

// ─── Multi-Instance Sweep ───────────────────────────────────────

it('E2E: sweep processes multiple instances correctly', function (): void {
    $machines = [];

    // Create 5 machines
    for ($i = 0; $i < 5; $i++) {
        $machine = AfterTimerMachine::create();
        $machine->persist();
        $machines[] = $machine->state->history->first()->root_event_id;
    }

    // Backdate first 3 past deadline
    for ($i = 0; $i < 3; $i++) {
        MachineCurrentState::forInstance($machines[$i])
            ->update(['state_entered_at' => now()->subDays(8)]);
    }

    // Sweep
    $this->artisan('machine:process-timers', ['--class' => AfterTimerMachine::class]);

    // Machines 0,1,2: cancelled
    for ($i = 0; $i < 3; $i++) {
        $restored = AfterTimerMachine::create(state: $machines[$i]);
        expect($restored->state->currentStateDefinition->id)->toBe('after_timer.cancelled');
    }

    // Machines 3,4: still awaiting_payment
    for ($i = 3; $i < 5; $i++) {
        $restored = AfterTimerMachine::create(state: $machines[$i]);
        expect($restored->state->currentStateDefinition->id)->toBe('after_timer.awaiting_payment');
    }

    // Verify timer fires: 3 fired, 2 no record
    expect(MachineTimerFire::count())->toBe(3);
});

// ─── Batch Size ─────────────────────────────────────────────────

it('E2E: sweep respects batch_size config', function (): void {
    // Create 5 machines, all past deadline
    $machines = [];
    for ($i = 0; $i < 5; $i++) {
        $machine = AfterTimerMachine::create();
        $machine->persist();
        $machines[] = $machine->state->history->first()->root_event_id;
        MachineCurrentState::forInstance($machines[$i])
            ->update(['state_entered_at' => now()->subDays(8)]);
    }

    // Set batch size to 2
    config(['machine.timers.batch_size' => 2]);

    // First sweep: processes at most 2
    $this->artisan('machine:process-timers', ['--class' => AfterTimerMachine::class]);

    $cancelled = 0;
    foreach ($machines as $rootId) {
        $restored = AfterTimerMachine::create(state: $rootId);
        if ($restored->state->currentStateDefinition->id === 'after_timer.cancelled') {
            $cancelled++;
        }
    }

    expect($cancelled)->toBe(2);

    // Second sweep: processes next 2
    $this->artisan('machine:process-timers', ['--class' => AfterTimerMachine::class]);

    $cancelled = 0;
    foreach ($machines as $rootId) {
        $restored = AfterTimerMachine::create(state: $rootId);
        if ($restored->state->currentStateDefinition->id === 'after_timer.cancelled') {
            $cancelled++;
        }
    }

    expect($cancelled)->toBe(4);

    // Third sweep: processes last 1
    $this->artisan('machine:process-timers', ['--class' => AfterTimerMachine::class]);

    $cancelled = 0;
    foreach ($machines as $rootId) {
        $restored = AfterTimerMachine::create(state: $rootId);
        if ($restored->state->currentStateDefinition->id === 'after_timer.cancelled') {
            $cancelled++;
        }
    }

    expect($cancelled)->toBe(5);
});
