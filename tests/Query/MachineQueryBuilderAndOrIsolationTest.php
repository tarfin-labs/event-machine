<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\QueryBuilderTestMachine;

describe('Chained inState AND', function (): void {
    test('AND on parallel machine returns instance in both states', function (): void {
        // Simulate parallel: same root_event_id has rows for both 'idle' and 'active' state IDs
        $rootEventId = '01TESTAND0000000000000001';

        MachineCurrentState::create([
            'root_event_id'    => $rootEventId,
            'machine_class'    => QueryBuilderTestMachine::class,
            'state_id'         => 'qb_test.idle',
            'state_entered_at' => now(),
        ]);
        MachineCurrentState::create([
            'root_event_id'    => $rootEventId,
            'machine_class'    => QueryBuilderTestMachine::class,
            'state_id'         => 'qb_test.active',
            'state_entered_at' => now(),
        ]);

        // AND: instance must be in BOTH idle AND active (both exist for this root_event_id)
        $results = QueryBuilderTestMachine::query()
            ->inState('idle')
            ->inState('active')
            ->get();

        expect($results)->toHaveCount(1);
    });

    test('AND on simple machine returns empty when states are incompatible', function (): void {
        createPersistedQBMachine('idle');

        $results = QueryBuilderTestMachine::query()
            ->inState('idle')
            ->inState('active')
            ->get();

        expect($results)->toBeEmpty();
    });
});

describe('inAnyState OR Isolation', function (): void {
    test('inAnyState returns instances in either state', function (): void {
        createPersistedQBMachine('idle');
        createPersistedQBMachine('active');
        createPersistedQBMachine('completed');

        $results = QueryBuilderTestMachine::query()
            ->inAnyState(['idle', 'active'])
            ->get();

        expect($results)->toHaveCount(2);
    });

    test('notInState combined with inAnyState does not leak OR', function (): void {
        createPersistedQBMachine('idle');
        createPersistedQBMachine('active');
        createPersistedQBMachine('completed');

        // Should return only active (not idle because notInState, not completed because not in anyState)
        $results = QueryBuilderTestMachine::query()
            ->notInState('idle')
            ->inAnyState(['idle', 'active'])
            ->get();

        expect($results)->toHaveCount(1);
        expect($results->first()->stateId)->toContain('active');
    });
});
