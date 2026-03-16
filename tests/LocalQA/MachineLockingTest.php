<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncParentMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

it('LocalQA: async completion releases lock — no stale locks after processing', function (): void {
    $parent = AsyncParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();
    $rootEventId = $parent->state->history->first()->root_event_id;

    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 30);

    expect($completed)->toBeTrue();

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0);
});

it('LocalQA: 5 parallel async delegations do not deadlock', function (): void {
    $ids = [];
    for ($i = 0; $i < 5; $i++) {
        $p = AsyncParentMachine::create();
        $p->send(['type' => 'START']);
        $p->persist();
        $ids[] = $p->state->history->first()->root_event_id;
    }

    $allDone = LocalQATestCase::waitFor(function () use ($ids) {
        foreach ($ids as $id) {
            $cs = MachineCurrentState::where('root_event_id', $id)->first();
            if (!$cs || !str_contains($cs->state_id, 'completed')) {
                return false;
            }
        }

        return true;
    }, timeoutSeconds: 45);

    expect($allDone)->toBeTrue('Some parallel delegations deadlocked');

    // Zero stale locks total
    $locks = DB::table('machine_locks')->count();
    expect($locks)->toBe(0);
});
