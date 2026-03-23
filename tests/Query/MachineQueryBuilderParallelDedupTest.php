<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\QueryBuilderTestMachine;

describe('Parallel State Deduplication', function (): void {
    test('parallel machine instance appears once in results', function (): void {
        // Simulate a parallel machine with 2 rows for the same root_event_id
        $rootEventId = '01TESTPARALLEL00000000001';

        MachineCurrentState::create([
            'root_event_id'    => $rootEventId,
            'machine_class'    => QueryBuilderTestMachine::class,
            'state_id'         => 'qb_test.region_a.working',
            'state_entered_at' => now()->subMinutes(2),
        ]);
        MachineCurrentState::create([
            'root_event_id'    => $rootEventId,
            'machine_class'    => QueryBuilderTestMachine::class,
            'state_id'         => 'qb_test.region_b.working',
            'state_entered_at' => now()->subMinute(),
        ]);

        $results = QueryBuilderTestMachine::query()
            ->inState('qb_test.*')
            ->get();

        expect($results)->toHaveCount(1);
        expect($results->first()->machineId)->toBe($rootEventId);
    });

    test('stateIds contains all active state IDs for parallel instance', function (): void {
        $rootEventId = '01TESTPARALLEL00000000002';

        MachineCurrentState::create([
            'root_event_id'    => $rootEventId,
            'machine_class'    => QueryBuilderTestMachine::class,
            'state_id'         => 'qb_test.region_a.working',
            'state_entered_at' => now()->subMinutes(2),
        ]);
        MachineCurrentState::create([
            'root_event_id'    => $rootEventId,
            'machine_class'    => QueryBuilderTestMachine::class,
            'state_id'         => 'qb_test.region_b.idle',
            'state_entered_at' => now()->subMinute(),
        ]);

        $result = QueryBuilderTestMachine::query()
            ->inState('qb_test.*')
            ->first();

        expect($result->stateIds)->toHaveCount(2);
        expect($result->stateIds)->toContain('qb_test.region_a.working');
        expect($result->stateIds)->toContain('qb_test.region_b.idle');
    });

    test('stateId is the most recently entered state', function (): void {
        $rootEventId = '01TESTPARALLEL00000000003';

        MachineCurrentState::create([
            'root_event_id'    => $rootEventId,
            'machine_class'    => QueryBuilderTestMachine::class,
            'state_id'         => 'qb_test.region_a.old',
            'state_entered_at' => now()->subHour(),
        ]);
        MachineCurrentState::create([
            'root_event_id'    => $rootEventId,
            'machine_class'    => QueryBuilderTestMachine::class,
            'state_id'         => 'qb_test.region_b.recent',
            'state_entered_at' => now(),
        ]);

        $result = QueryBuilderTestMachine::query()
            ->inState('qb_test.*')
            ->first();

        expect($result->stateId)->toBe('qb_test.region_b.recent');
    });

    test('count returns 1 for parallel instance with multiple rows', function (): void {
        $rootEventId = '01TESTPARALLEL00000000004';

        MachineCurrentState::create([
            'root_event_id'    => $rootEventId,
            'machine_class'    => QueryBuilderTestMachine::class,
            'state_id'         => 'qb_test.region_a.working',
            'state_entered_at' => now(),
        ]);
        MachineCurrentState::create([
            'root_event_id'    => $rootEventId,
            'machine_class'    => QueryBuilderTestMachine::class,
            'state_id'         => 'qb_test.region_b.working',
            'state_entered_at' => now(),
        ]);

        $count = QueryBuilderTestMachine::query()
            ->inState('qb_test.*')
            ->count();

        expect($count)->toBe(1);
    });
});
