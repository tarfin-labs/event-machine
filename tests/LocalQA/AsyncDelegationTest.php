<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncAutoCompleteParentMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
});

// ═══════════════════════════════════════════════════════════════
//  Async Delegation — Real Horizon
// ═══════════════════════════════════════════════════════════════

it('LocalQA: async delegation creates machine_children and dispatches to Horizon', function (): void {
    $parent = AsyncAutoCompleteParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();

    $rootEventId = $parent->state->history->first()->root_event_id;

    // Wait for Horizon to complete the delegation (ImmediateChildMachine auto-completes)
    // Note: no intermediate state assertion — child starts in final state,
    // so Horizon may complete the entire chain before we can observe 'processing'.
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 30);

    expect($completed)->toBeTrue('Async delegation not completed by Horizon');

    // machine_children record should exist (status may be 'completed' by now)
    $child = DB::table('machine_children')
        ->where('parent_root_event_id', $rootEventId)
        ->first();
    expect($child)->not->toBeNull();
    expect($child->status)->toBe('completed');
});

// Machine::fake test moved to unit tests — LocalQA must use real Horizon, never Machine::fake().
