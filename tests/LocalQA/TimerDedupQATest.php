<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\AfterTimerMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
});

// ═══════════════════════════════════════════════════════════════
//  Concurrent Timer Sweep Dedup — Real Horizon
// ═══════════════════════════════════════════════════════════════

it('LocalQA: two simultaneous timer sweeps do not double-fire', function (): void {
    $machine = AfterTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Backdate past @after deadline
    DB::table('machine_current_states')
        ->where('root_event_id', $rootEventId)
        ->update(['state_entered_at' => now()->subDays(8)]);

    // Run TWO sweeps back-to-back (simulates concurrent scheduler invocations)
    Artisan::call('machine:process-timers', ['--class' => AfterTimerMachine::class]);
    Artisan::call('machine:process-timers', ['--class' => AfterTimerMachine::class]);

    // Wait for machine to transition
    $expired = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'cancelled');
    }, timeoutSeconds: 60, description: 'timer dedup: waiting for machine to transition after double sweep');

    expect($expired)->toBeTrue('Timer did not fire');

    // Assert: exactly 1 fire record (dedup prevents double-fire)
    $fires = DB::table('machine_timer_fires')
        ->where('root_event_id', $rootEventId)
        ->count();
    expect($fires)->toBe(1, 'Expected exactly 1 fire record, got '.$fires);
});
