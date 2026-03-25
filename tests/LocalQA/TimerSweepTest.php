<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Tarfinlabs\EventMachine\Support\Timer;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\AfterTimerMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\EveryTimerMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\EveryWithMaxMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
});

// ═══════════════════════════════════════════════════════════════
//  @after Timer — One-Shot via Horizon Bus::batch
// ═══════════════════════════════════════════════════════════════

it('LocalQA: after timer fires via Horizon when past deadline', function (): void {
    $machine = AfterTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    DB::table('machine_current_states')
        ->where('root_event_id', $rootEventId)
        ->update(['state_entered_at' => now()->subDays(8)]);

    Artisan::call('machine:process-timers', ['--class' => AfterTimerMachine::class]);

    $expired = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'cancelled');
    }, timeoutSeconds: 60);

    expect($expired)->toBeTrue('After timer not processed by Horizon');

    $fire = DB::table('machine_timer_fires')
        ->where('root_event_id', $rootEventId)
        ->first();
    expect($fire->status)->toBe('fired');
});

it('LocalQA: after timer does NOT fire before deadline', function (): void {
    $machine = AfterTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    Artisan::call('machine:process-timers', ['--class' => AfterTimerMachine::class]);

    // Negative assertion: verify timer does NOT fire when not past deadline.
    // sleep required — cannot waitFor absence.
    sleep(1);

    $fire = DB::table('machine_timer_fires')
        ->where('root_event_id', $rootEventId)
        ->first();
    expect($fire)->toBeNull();

    $restored = AfterTimerMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toContain('awaiting_payment');
});

it('LocalQA: after timer dedup — double sweep does not double-fire', function (): void {
    $machine = AfterTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    DB::table('machine_current_states')
        ->where('root_event_id', $rootEventId)
        ->update(['state_entered_at' => now()->subDays(8)]);

    Artisan::call('machine:process-timers', ['--class' => AfterTimerMachine::class]);

    LocalQATestCase::waitFor(function () use ($rootEventId) {
        return DB::table('machine_timer_fires')
            ->where('root_event_id', $rootEventId)
            ->exists();
    }, timeoutSeconds: 60);

    // Second sweep — should be deduped
    Artisan::call('machine:process-timers', ['--class' => AfterTimerMachine::class]);

    LocalQATestCase::waitFor(function () use ($rootEventId) {
        $fire = DB::table('machine_timer_fires')
            ->where('root_event_id', $rootEventId)
            ->first();

        return $fire && $fire->status === 'fired';
    }, timeoutSeconds: 60, description: 'after timer dedup: waiting for fire status=fired');

    $fires = DB::table('machine_timer_fires')
        ->where('root_event_id', $rootEventId)
        ->count();
    expect($fires)->toBe(1);
});

// ═══════════════════════════════════════════════════════════════
//  @every Timer — Recurring via Horizon
// ═══════════════════════════════════════════════════════════════

it('LocalQA: every timer fires via Horizon', function (): void {
    $machine = EveryTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    DB::table('machine_current_states')
        ->where('root_event_id', $rootEventId)
        ->update(['state_entered_at' => now()->subDays(31)]);

    Artisan::call('machine:process-timers', ['--class' => EveryTimerMachine::class]);

    $fired = LocalQATestCase::waitFor(function () use ($rootEventId) {
        return DB::table('machine_timer_fires')
            ->where('root_event_id', $rootEventId)
            ->where('timer_key', 'LIKE', '%BILLING%')
            ->exists();
    }, timeoutSeconds: 60);

    expect($fired)->toBeTrue('Every timer not processed by Horizon');

    $fire = DB::table('machine_timer_fires')
        ->where('root_event_id', $rootEventId)
        ->first();
    expect($fire->status)->toBe('active')
        ->and((int) $fire->fire_count)->toBeGreaterThanOrEqual(1);
});

// ═══════════════════════════════════════════════════════════════
//  @every max/then — Horizon
// ═══════════════════════════════════════════════════════════════

it('LocalQA: every max/then transitions machine to failed via Horizon', function (): void {
    $machine = EveryWithMaxMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Simulate 3 fires then max
    for ($i = 0; $i < 3; $i++) {
        DB::table('machine_current_states')
            ->where('root_event_id', $rootEventId)
            ->update(['state_entered_at' => now()->subHours(8)]);

        DB::table('machine_timer_fires')
            ->where('root_event_id', $rootEventId)
            ->update(['last_fired_at' => now()->subHours(8)]);

        Artisan::call('machine:process-timers', ['--class' => EveryWithMaxMachine::class]);

        LocalQATestCase::waitFor(function () use ($rootEventId, $i) {
            $fire = DB::table('machine_timer_fires')
                ->where('root_event_id', $rootEventId)
                ->first();

            if (!$fire || (int) $fire->fire_count < ($i + 1)) {
                return false;
            }

            // Also wait for Horizon to process the timer job (context updated)
            $restored = EveryWithMaxMachine::create(state: $rootEventId);

            return $restored->state->context->get('retry_count') >= ($i + 1);
        }, timeoutSeconds: 60, description: 'every max/then: cycle '.($i + 1).' fire_count+retry_count');
    }

    // After max, sweep should send then event
    DB::table('machine_timer_fires')
        ->where('root_event_id', $rootEventId)
        ->update(['last_fired_at' => now()->subHours(8)]);

    Artisan::call('machine:process-timers', ['--class' => EveryWithMaxMachine::class]);

    $failed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'failed');
    }, timeoutSeconds: 60);

    expect($failed)->toBeTrue('Machine not failed after max retries');
});

// ═══════════════════════════════════════════════════════════════
//  Multiple instances — selective firing
// ═══════════════════════════════════════════════════════════════

it('LocalQA: timer sweep selectively fires only past-deadline instances', function (): void {
    $ids = [];
    for ($i = 0; $i < 3; $i++) {
        $m = AfterTimerMachine::create();
        $m->persist();
        $ids[] = $m->state->history->first()->root_event_id;
    }

    // Only backdate first
    DB::table('machine_current_states')
        ->where('root_event_id', $ids[0])
        ->update(['state_entered_at' => now()->subDays(8)]);

    Artisan::call('machine:process-timers', ['--class' => AfterTimerMachine::class]);

    $firstExpired = LocalQATestCase::waitFor(function () use ($ids) {
        $cs = MachineCurrentState::where('root_event_id', $ids[0])->first();

        return $cs && str_contains($cs->state_id, 'cancelled');
    }, timeoutSeconds: 60);

    expect($firstExpired)->toBeTrue();

    // Others still active
    expect(AfterTimerMachine::create(state: $ids[1])->state->currentStateDefinition->id)->toContain('awaiting_payment');
    expect(AfterTimerMachine::create(state: $ids[2])->state->currentStateDefinition->id)->toContain('awaiting_payment');
});
