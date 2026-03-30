<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\QueryBuilderTestMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\QueryBuilderAmbiguousMachine;

describe('Ambiguous Leaf Name Resolution', function (): void {
    test('same leaf name in multiple regions matches all occurrences', function (): void {
        // Both regions start in 'idle' — the leaf name 'idle' exists twice:
        //   qb_ambiguous.processing.region_a.idle
        //   qb_ambiguous.processing.region_b.idle
        $machine = QueryBuilderAmbiguousMachine::create();
        $machine->persist();

        // inState('idle') should match (instance has 'idle' in both regions)
        $results = QueryBuilderAmbiguousMachine::query()
            ->inState('idle')
            ->get();

        expect($results)->toHaveCount(1);
    });

    test('partial path disambiguates between same-name states', function (): void {
        $machine = QueryBuilderAmbiguousMachine::create();
        $machine->persist();

        // 'region_a.idle' should match only qb_ambiguous.processing.region_a.idle
        $resolved = QueryBuilderAmbiguousMachine::query()
            ->resolveStateIds('region_a.idle');

        expect($resolved['exact'])->toHaveCount(1);
        expect($resolved['exact'][0])->toContain('region_a.idle');
    });

    test('partial path with parent resolves correctly', function (): void {
        $machine = QueryBuilderAmbiguousMachine::create();
        $machine->persist();

        // 'region_b.working' — only one match
        $resolved = QueryBuilderAmbiguousMachine::query()
            ->resolveStateIds('region_b.idle');

        expect($resolved['exact'])->toHaveCount(1);
        expect($resolved['exact'][0])->toContain('region_b.idle');
    });

    test('compound parent match returns all descendants', function (): void {
        $machine = QueryBuilderAmbiguousMachine::create();
        $machine->persist();

        // 'region_a' is a compound state — should match all its children
        $resolved = QueryBuilderAmbiguousMachine::query()
            ->resolveStateIds('region_a');

        expect($resolved['patterns'])->toHaveCount(1);
        expect($resolved['patterns'][0])->toContain('region_a.%');
    });
});

describe('inAllStates Explicit AND', function (): void {
    test('inAllStates matches parallel instance in both states', function (): void {
        // Simulate parallel: same root_event_id has two different state rows
        $rootEventId = '01TESTALLSTATES000000001';

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

        $results = QueryBuilderTestMachine::query()
            ->inAllStates(['idle', 'active'])
            ->get();

        expect($results)->toHaveCount(1);
    });

    test('inAllStates returns empty when instance lacks one state', function (): void {
        createPersistedQBMachine('idle');

        $results = QueryBuilderTestMachine::query()
            ->inAllStates(['idle', 'active'])
            ->get();

        expect($results)->toBeEmpty();
    });

    test('inAllStates is equivalent to chained inState calls', function (): void {
        $rootEventId = '01TESTALLSTATES000000002';

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

        $chained = QueryBuilderTestMachine::query()
            ->inState('idle')
            ->inState('active')
            ->count();

        $explicit = QueryBuilderTestMachine::query()
            ->inAllStates(['idle', 'active'])
            ->count();

        expect($chained)->toBe($explicit);
    });
});
