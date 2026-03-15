<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Tarfinlabs\EventMachine\Support\Timer;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Models\MachineTimerFire;
use Tarfinlabs\EventMachine\Definition\TimerDefinition;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\AfterTimerMachine;

// ─── Self-Loop Edge Case ────────────────────────────────────────

it('self-loop does NOT reset after deadline', function (): void {
    Bus::fake();

    $machine = AfterTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Backdate to 5 days ago
    MachineCurrentState::forInstance($rootEventId)
        ->update(['state_entered_at' => now()->subDays(5)]);

    $originalEntry = MachineCurrentState::forInstance($rootEventId)->first()->state_entered_at;

    // Persist again (self-loop) — should NOT reset state_entered_at
    $machine->persist();

    $afterEntry = MachineCurrentState::forInstance($rootEventId)->first()->state_entered_at;

    expect($afterEntry->equalTo($originalEntry))->toBeTrue();
});

// ─── Timer Key Generation ───────────────────────────────────────

it('timer key format is state_id:event_name:seconds', function (): void {
    $timer = TimerDefinition::fromAfter(
        timer: Timer::days(7),
        eventName: 'ORDER_EXPIRED',
        stateId: 'order.awaiting_payment',
    );

    expect($timer->key())->toBe('order.awaiting_payment:ORDER_EXPIRED:604800');
});

it('every timer key includes interval', function (): void {
    $timer = TimerDefinition::fromEvery(
        timer: Timer::hours(6),
        eventName: 'RETRY',
        stateId: 'order.retrying',
        max: 3,
        then: 'MAX_RETRIES',
    );

    expect($timer->key())->toBe('order.retrying:RETRY:21600')
        ->and($timer->max)->toBe(3)
        ->and($timer->then)->toBe('MAX_RETRIES');
});

// ─── Machine Timer Fires Model ──────────────────────────────────

it('MachineTimerFire isFired returns true for fired status', function (): void {
    $fire = new MachineTimerFire([
        'root_event_id' => 'test-id',
        'timer_key'     => 'test.state:EVENT:3600',
        'last_fired_at' => now(),
        'fire_count'    => 1,
        'status'        => MachineTimerFire::STATUS_FIRED,
    ]);

    expect($fire->isFired())->toBeTrue()
        ->and($fire->isExhausted())->toBeFalse();
});

it('MachineTimerFire isExhausted returns true for exhausted status', function (): void {
    $fire = new MachineTimerFire([
        'root_event_id' => 'test-id',
        'timer_key'     => 'test.state:EVENT:3600',
        'last_fired_at' => now(),
        'fire_count'    => 3,
        'status'        => MachineTimerFire::STATUS_EXHAUSTED,
    ]);

    expect($fire->isExhausted())->toBeTrue()
        ->and($fire->isFired())->toBeFalse();
});

// ─── Mixed Array (multi-branch + timer) ─────────────────────────

it('mixed array: multi-branch guarded transition with after key', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'mixed_array',
            'initial' => 'waiting',
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
                'isExpiredGuard' => fn (): bool => true,
            ],
        ],
    );

    $state      = $machine->idMap['mixed_array.waiting'];
    $transition = $state->transitionDefinitions['TIMEOUT'];

    // Timer extracted
    expect($transition->timerDefinition)->not->toBeNull()
        ->and($transition->timerDefinition->isAfter())->toBeTrue()
        ->and($transition->timerDefinition->delaySeconds)->toBe(604800);

    // Branches still work (2 branches)
    expect($transition->branches)->toHaveCount(2)
        ->and($transition->isGuarded)->toBeTrue();
});

// ─── Multi-Branch Guard + Timer Execution ───────────────────────

it('multi-branch guarded after: guard pass goes to first branch', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'mb_guard',
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

    $state = $machine->getInitialState();
    // Guard passes → first branch (cancelled)
    $state = $machine->transition(event: ['type' => 'TIMEOUT'], state: $state);
    expect($state->value)->toBe(['mb_guard.cancelled']);
});

it('multi-branch guarded after: guard fail goes to fallback branch', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'mb_fallback',
            'initial' => 'waiting',
            'context' => ['is_expired' => false],
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

    $state = $machine->getInitialState();
    // Guard fails → fallback branch (extended)
    $state = $machine->transition(event: ['type' => 'TIMEOUT'], state: $state);
    expect($state->value)->toBe(['mb_fallback.extended']);
});

// ─── Multiple after + every on same state ───────────────────────

it('after and every coexist on same state different events', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'coexist',
            'initial' => 'active',
            'context' => [],
            'states'  => [
                'active' => [
                    'on' => [
                        'EXPIRED'   => ['target' => 'done', 'after' => Timer::days(7)],
                        'HEARTBEAT' => ['actions' => 'heartbeatAction', 'every' => Timer::hours(1)],
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'heartbeatAction' => function (): void {},
            ],
        ],
    );

    $state = $machine->idMap['coexist.active'];

    $expired   = $state->transitionDefinitions['EXPIRED'];
    $heartbeat = $state->transitionDefinitions['HEARTBEAT'];

    expect($expired->timerDefinition)->not->toBeNull()
        ->and($expired->timerDefinition->isAfter())->toBeTrue()
        ->and($heartbeat->timerDefinition)->not->toBeNull()
        ->and($heartbeat->timerDefinition->isEvery())->toBeTrue();
});
