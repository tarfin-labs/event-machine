<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\AfterTimerMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScheduledMachines\ScheduledMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScheduledMachines\ExpiredApplicationsResolver;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncAutoCompleteParentMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
    ExpiredApplicationsResolver::$ids = null;
});

// ═══════════════════════════════════════════════════════════════
//  Timer + Schedule on same machine — independent via Horizon
// ═══════════════════════════════════════════════════════════════

it('LocalQA: timer and schedule on different instances work independently via Horizon', function (): void {
    // Instance 1: will receive schedule
    $m1 = ScheduledMachine::create();
    $m1->persist();
    $id1 = $m1->state->history->first()->root_event_id;

    // Instance 2: will receive timer (if ScheduledMachine had timers)
    // Using AfterTimerMachine for timer test
    $m2 = AfterTimerMachine::create();
    $m2->persist();
    $id2 = $m2->state->history->first()->root_event_id;

    // Schedule on instance 1
    ExpiredApplicationsResolver::setUp([$id1]);
    Artisan::call('machine:process-scheduled', [
        '--class' => ScheduledMachine::class,
        '--event' => 'CHECK_EXPIRY',
    ]);

    // Timer on instance 2
    DB::table('machine_current_states')
        ->where('root_event_id', $id2)
        ->update(['state_entered_at' => now()->subDays(8)]);
    Artisan::call('machine:process-timers', ['--class' => AfterTimerMachine::class]);

    // Wait for both
    $m1Expired = LocalQATestCase::waitFor(function () use ($id1) {
        $cs = MachineCurrentState::where('root_event_id', $id1)->first();

        return $cs && str_contains($cs->state_id, 'expired');
    }, timeoutSeconds: 45);

    $m2Cancelled = LocalQATestCase::waitFor(function () use ($id2) {
        $cs = MachineCurrentState::where('root_event_id', $id2)->first();

        return $cs && str_contains($cs->state_id, 'cancelled');
    }, timeoutSeconds: 45);

    expect($m1Expired)->toBeTrue('Schedule not processed')
        ->and($m2Cancelled)->toBeTrue('Timer not processed');
});

// ═══════════════════════════════════════════════════════════════
//  Concurrent async completions — idempotent
// ═══════════════════════════════════════════════════════════════

it('LocalQA: async completion is idempotent — only one done event in history', function (): void {
    $parent = AsyncAutoCompleteParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();
    $rootEventId = $parent->state->history->first()->root_event_id;

    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 30);

    expect($completed)->toBeTrue();

    // Verify exactly one child.done event
    $doneEvents = DB::table('machine_events')
        ->where('root_event_id', $rootEventId)
        ->where('type', 'LIKE', '%.child.%.done')
        ->count();

    expect($doneEvents)->toBe(1);
});

// ═══════════════════════════════════════════════════════════════
//  Machine faking with async delegation
// ═══════════════════════════════════════════════════════════════

it('LocalQA: machine faking intercepts async delegation on Horizon', function (): void {
    ImmediateChildMachine::fake(result: ['test' => 'faked']);

    $parent = AsyncAutoCompleteParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();
    $rootEventId = $parent->state->history->first()->root_event_id;

    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 30);

    expect($completed)->toBeTrue('Faked async delegation not completed');

    ImmediateChildMachine::assertInvoked();
    Machine::resetMachineFakes();
});

// ═══════════════════════════════════════════════════════════════
//  Multiple machines — independent persistence in MySQL
// ═══════════════════════════════════════════════════════════════

it('LocalQA: 10 machines created and restored independently from MySQL', function (): void {
    $ids = [];
    for ($i = 0; $i < 10; $i++) {
        $m = AfterTimerMachine::create();
        $m->persist();
        $ids[] = $m->state->history->first()->root_event_id;
    }

    // Verify all created
    $count = DB::table('machine_current_states')
        ->whereIn('root_event_id', $ids)
        ->count();
    expect($count)->toBe(10);

    // Restore each and verify state
    foreach ($ids as $id) {
        $restored = AfterTimerMachine::create(state: $id);
        expect($restored->state->currentStateDefinition->id)->toContain('awaiting_payment');
        expect($restored->state->context->machineId())->toBe($id);
    }
});
