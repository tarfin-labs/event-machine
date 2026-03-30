<?php

declare(strict_types=1);

use Illuminate\Pagination\LengthAwarePaginator;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\QueryBuilderTestMachine;

describe('Pagination', function (): void {
    test('paginate returns LengthAwarePaginator with correct counts', function (): void {
        createPersistedQBMachine('idle');
        createPersistedQBMachine('idle');
        createPersistedQBMachine('idle');

        $paginator = QueryBuilderTestMachine::query()
            ->inState('idle')
            ->paginate(2);

        expect($paginator)->toBeInstanceOf(LengthAwarePaginator::class);
        expect($paginator->total())->toBe(3);
        expect($paginator->perPage())->toBe(2);
        expect($paginator->items())->toHaveCount(2);
    });

    test('paginate deduplicates parallel instances', function (): void {
        // Create 2 parallel instances (2 rows each)
        foreach (['01TESTPAG000000000000001', '01TESTPAG000000000000002'] as $id) {
            MachineCurrentState::create([
                'root_event_id'    => $id,
                'machine_class'    => QueryBuilderTestMachine::class,
                'state_id'         => 'qb_test.region_a.working',
                'state_entered_at' => now(),
            ]);
            MachineCurrentState::create([
                'root_event_id'    => $id,
                'machine_class'    => QueryBuilderTestMachine::class,
                'state_id'         => 'qb_test.region_b.working',
                'state_entered_at' => now(),
            ]);
        }

        $paginator = QueryBuilderTestMachine::query()
            ->inState('qb_test.*')
            ->paginate(10);

        expect($paginator->total())->toBe(2);
        expect($paginator->items())->toHaveCount(2);
    });
});

describe('Ordering', function (): void {
    test('latest() orders by most recently entered state descending', function (): void {
        // Create machines at different times
        $old = createPersistedQBMachine('idle');

        // Travel forward to ensure different timestamps
        $this->travel(1)->hours();
        $recent = createPersistedQBMachine('idle');

        $results = QueryBuilderTestMachine::query()
            ->inState('idle')
            ->latest()
            ->get();

        expect($results)->toHaveCount(2);
        expect($results->first()->machineId)->toBe(
            $recent->state->history->first()->root_event_id
        );
    });

    test('oldest() orders by earliest entered state ascending', function (): void {
        $old = createPersistedQBMachine('idle');

        $this->travel(1)->hours();
        $recent = createPersistedQBMachine('idle');

        $results = QueryBuilderTestMachine::query()
            ->inState('idle')
            ->oldest()
            ->get();

        expect($results)->toHaveCount(2);
        expect($results->first()->machineId)->toBe(
            $old->state->history->first()->root_event_id
        );
    });

    test('enteredBefore filters by state_entered_at', function (): void {
        createPersistedQBMachine('idle');

        $this->travel(2)->hours();
        createPersistedQBMachine('idle');

        $cutoff  = now()->subHour();
        $results = QueryBuilderTestMachine::query()
            ->inState('idle')
            ->enteredBefore($cutoff)
            ->get();

        expect($results)->toHaveCount(1);
    });

    test('enteredAfter filters by state_entered_at', function (): void {
        createPersistedQBMachine('idle');

        $cutoff = now();
        $this->travel(2)->hours();
        createPersistedQBMachine('idle');

        $results = QueryBuilderTestMachine::query()
            ->inState('idle')
            ->enteredAfter($cutoff)
            ->get();

        expect($results)->toHaveCount(1);
    });
});
