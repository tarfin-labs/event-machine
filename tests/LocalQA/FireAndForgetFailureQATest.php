<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\FailingFireAndForgetParentMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

// ═══════════════════════════════════════════════════════════════
//  Fire-and-Forget Failing Child — Isolation
// ═══════════════════════════════════════════════════════════════

it('LocalQA: fire-and-forget child failure does not affect parent', function (): void {
    $parent = FailingFireAndForgetParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();
    $rootEventId = $parent->state->history->first()->root_event_id;

    // Parent should be at 'processing' immediately (fire-and-forget)
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs->state_id)->toContain('processing');

    // Wait for child to fail (appears in failed_jobs after retries exhaust)
    $childFailed = LocalQATestCase::waitFor(function () {
        return DB::table('failed_jobs')->count() > 0;
    }, timeoutSeconds: 60, description: 'fire-and-forget failure: waiting for child to appear in failed_jobs');

    expect($childFailed)->toBeTrue('Failing child did not appear in failed_jobs');

    // Assert: parent is still unaffected at 'processing'
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs->state_id)->toContain('processing');

    // Assert: parent context is intact
    $restored = FailingFireAndForgetParentMachine::create(state: $rootEventId);
    expect($restored->state->context->get('parent_ok'))->toBeTrue();

    // Assert: no MachineChild record (fire-and-forget has no tracking)
    expect(DB::table('machine_children')
        ->where('parent_root_event_id', $rootEventId)->count()
    )->toBe(0);
});
