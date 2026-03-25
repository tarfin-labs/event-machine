<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Support\Timer;
use Tarfinlabs\EventMachine\Models\MachineTimerFire;
use Tarfinlabs\EventMachine\Definition\TimerDefinition;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\AfterTimerMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\EveryTimerMachine;

// ═══════════════════════════════════════════
//  1. Scope Visibility — do public scopes work?
// ═══════════════════════════════════════════

it('MachineCurrentState::forInstance scope works as public', function (): void {
    $machine = AfterTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $results = MachineCurrentState::forInstance($rootEventId)->get();
    expect($results)->toHaveCount(1)
        ->and($results->first()->state_id)->toBe('after_timer.awaiting_payment');
});

it('MachineCurrentState::forSweep scope works as public', function (): void {
    $machine = AfterTimerMachine::create();
    $machine->persist();

    $results = MachineCurrentState::forSweep(AfterTimerMachine::class, 'after_timer.awaiting_payment')->get();
    expect($results)->toHaveCount(1);
});

it('MachineCurrentState::pastDeadline scope works as public', function (): void {
    $machine = AfterTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    MachineCurrentState::forInstance($rootEventId)
        ->update(['state_entered_at' => now()->subDays(8)]);

    $results = MachineCurrentState::pastDeadline(now()->subDays(7))->get();
    expect($results)->toHaveCount(1);
});

// ═══════════════════════════════════════════
//  2. insertOrIgnore dedup — does it prevent double fire?
// ═══════════════════════════════════════════

it('insertOrIgnore prevents duplicate timer fire records', function (): void {
    $machine = AfterTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $timerKey = 'after_timer.awaiting_payment:ORDER_EXPIRED:604800';

    // First insert succeeds
    $inserted1 = DB::table('machine_timer_fires')->insertOrIgnore([
        'root_event_id' => $rootEventId,
        'timer_key'     => $timerKey,
        'last_fired_at' => now(),
        'fire_count'    => 1,
        'status'        => MachineTimerFire::STATUS_FIRED,
    ]);

    expect($inserted1)->toBe(1);

    // Second insert is ignored (duplicate PK)
    $inserted2 = DB::table('machine_timer_fires')->insertOrIgnore([
        'root_event_id' => $rootEventId,
        'timer_key'     => $timerKey,
        'last_fired_at' => now(),
        'fire_count'    => 1,
        'status'        => MachineTimerFire::STATUS_FIRED,
    ]);

    expect($inserted2)->toBe(0);

    // Only one record exists
    expect(MachineTimerFire::where('root_event_id', $rootEventId)->count())->toBe(1);
});

// ═══════════════════════════════════════════
//  3. Timer validation edge cases
// ═══════════════════════════════════════════

it('Timer::seconds(1) is valid minimum', function (): void {
    expect(Timer::seconds(1)->inSeconds())->toBe(1);
});

it('Timer::seconds(0) throws', function (): void {
    Timer::seconds(0);
})->throws(InvalidArgumentException::class);

it('Timer::minutes(-1) throws', function (): void {
    Timer::minutes(-1);
})->throws(InvalidArgumentException::class);

// ═══════════════════════════════════════════
//  4. extractTimerConfig — all config formats
// ═══════════════════════════════════════════

it('extractTimerConfig: simple target + after', function (): void {
    $machine = MachineDefinition::define(config: [
        'id'     => 'ext1', 'initial' => 'a',
        'states' => [
            'a' => ['on' => ['E' => ['target' => 'b', 'after' => Timer::days(7)]]],
            'b' => ['type' => 'final'],
        ],
    ]);

    $t = $machine->idMap['ext1.a']->transitionDefinitions['E'];
    expect($t->timerDefinition)->not->toBeNull()
        ->and($t->timerDefinition->isAfter())->toBeTrue()
        ->and($t->branches)->toHaveCount(1)
        ->and($t->branches[0]->target->key)->toBe('b');
});

it('extractTimerConfig: action only + every (no target)', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'     => 'ext2', 'initial' => 'a',
            'states' => [
                'a' => ['on' => [
                    'TICK' => ['actions' => 'tickAction', 'every' => Timer::hours(1)],
                    'STOP' => 'b',
                ]],
                'b' => ['type' => 'final'],
            ],
        ],
        behavior: ['actions' => ['tickAction' => function (): void {}]],
    );

    $t = $machine->idMap['ext2.a']->transitionDefinitions['TICK'];
    expect($t->timerDefinition)->not->toBeNull()
        ->and($t->timerDefinition->isEvery())->toBeTrue()
        ->and($t->branches)->toHaveCount(1)
        ->and($t->branches[0]->target)->toBeNull() // no target, action only
        ->and($t->branches[0]->actions)->not->toBeNull();
});

it('extractTimerConfig: mixed array (multi-branch + after)', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'     => 'ext3', 'initial' => 'a',
            'states' => [
                'a' => ['on' => [
                    'E' => [
                        ['target' => 'b', 'guards' => 'g1'],
                        ['target' => 'c'],
                        'after'   => Timer::days(7),
                    ],
                ]],
                'b' => ['type' => 'final'],
                'c' => ['type' => 'final'],
            ],
        ],
        behavior: ['guards' => ['g1' => fn (): bool => true]],
    );

    $t = $machine->idMap['ext3.a']->transitionDefinitions['E'];
    expect($t->timerDefinition)->not->toBeNull()
        ->and($t->timerDefinition->isAfter())->toBeTrue()
        ->and($t->branches)->toHaveCount(2)
        ->and($t->isGuarded)->toBeTrue();
});

it('extractTimerConfig: every with max and then', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'     => 'ext4', 'initial' => 'a',
            'states' => [
                'a' => ['on' => [
                    'RETRY' => ['actions' => 'act', 'every' => Timer::hours(6), 'max' => 3, 'then' => 'DONE'],
                    'DONE'  => 'b',
                ]],
                'b' => ['type' => 'final'],
            ],
        ],
        behavior: ['actions' => ['act' => function (): void {}]],
    );

    $t = $machine->idMap['ext4.a']->transitionDefinitions['RETRY'];
    expect($t->timerDefinition)->not->toBeNull()
        ->and($t->timerDefinition->isEvery())->toBeTrue()
        ->and($t->timerDefinition->max)->toBe(3)
        ->and($t->timerDefinition->then)->toBe('DONE')
        ->and($t->timerDefinition->delaySeconds)->toBe(21600);
});

it('extractTimerConfig: no timer keys — timerDefinition is null', function (): void {
    $machine = MachineDefinition::define(config: [
        'id'     => 'ext5', 'initial' => 'a',
        'states' => [
            'a' => ['on' => ['E' => ['target' => 'b', 'guards' => 'g']]],
            'b' => ['type' => 'final'],
        ],
    ], behavior: ['guards' => ['g' => fn (): bool => true]]);

    $t = $machine->idMap['ext5.a']->transitionDefinitions['E'];
    expect($t->timerDefinition)->toBeNull();
});

// ═══════════════════════════════════════════
//  5. syncCurrentStates — first persist (no existing rows)
// ═══════════════════════════════════════════

it('syncCurrentStates creates rows on first persist', function (): void {
    $machine = AfterTimerMachine::create();

    // Before persist — no rows
    $rootEventId = $machine->state->history->first()->root_event_id;
    expect(MachineCurrentState::forInstance($rootEventId)->count())->toBe(0);

    $machine->persist();

    // After persist — 1 row
    expect(MachineCurrentState::forInstance($rootEventId)->count())->toBe(1);
});

it('syncCurrentStates handles multiple persists idempotently', function (): void {
    $machine = AfterTimerMachine::create();
    $machine->persist();
    $machine->persist();
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;
    expect(MachineCurrentState::forInstance($rootEventId)->count())->toBe(1);
});

// ═══════════════════════════════════════════
//  6. TimerDefinition key format
// ═══════════════════════════════════════════

it('TimerDefinition key uses correct format for after', function (): void {
    $td = TimerDefinition::fromAfter(Timer::days(7), 'ORDER_EXPIRED', 'order.awaiting');
    expect($td->key())->toBe('order.awaiting:ORDER_EXPIRED:604800');
});

it('TimerDefinition key uses correct format for every', function (): void {
    $td = TimerDefinition::fromEvery(Timer::hours(6), 'RETRY', 'order.retrying', 3, 'DONE');
    expect($td->key())->toBe('order.retrying:RETRY:21600');
});

// ═══════════════════════════════════════════
//  7. E2E: after fires via real pipeline with dedup
// ═══════════════════════════════════════════

it('verification: full after pipeline with atomic dedup', function (): void {
    $machine = AfterTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    MachineCurrentState::forInstance($rootEventId)
        ->update(['state_entered_at' => now()->subDays(8)]);

    // First sweep
    $this->artisan('machine:process-timers', ['--class' => AfterTimerMachine::class]);

    $restored = AfterTimerMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('after_timer.cancelled');

    // Verify dedup record
    $fire = MachineTimerFire::where('root_event_id', $rootEventId)->first();
    expect($fire->status)->toBe(MachineTimerFire::STATUS_FIRED)
        ->and($fire->fire_count)->toBe(1);
});

// ═══════════════════════════════════════════
//  8. TestMachine helpers work end-to-end
// ═══════════════════════════════════════════

it('verification: TestMachine advanceTimers full lifecycle', function (): void {
    AfterTimerMachine::test()
        ->assertState('awaiting_payment')
        ->assertHasTimer('ORDER_EXPIRED')
        ->assertTimerNotFired('ORDER_EXPIRED')
        ->advanceTimers(Timer::days(3))
        ->assertState('awaiting_payment')
        ->assertTimerNotFired('ORDER_EXPIRED')
        ->advanceTimers(Timer::days(8))
        ->assertState('cancelled')
        ->assertTimerFired('ORDER_EXPIRED')
        ->assertFinished();
});

it('verification: TestMachine every with multiple cycles', function (): void {
    EveryTimerMachine::test()
        ->assertState('active')
        ->assertHasTimer('BILLING')
        ->advanceTimers(Timer::days(31))
        ->assertContext('billingCount', 1)
        ->assertState('active')
        ->advanceTimers(Timer::days(31))
        ->assertContext('billingCount', 2);
});
