<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\QueryBuilderTestMachine;

describe('N+1 Protection — no unnecessary Machine restore', function (): void {
    test('get() does not query machine_events table', function (): void {
        createPersistedQBMachine('idle');
        createPersistedQBMachine('active');
        createPersistedQBMachine('completed');

        // Reset query log
        DB::enableQueryLog();
        DB::flushQueryLog();

        $results = QueryBuilderTestMachine::query()
            ->active()
            ->get();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // get() should only query machine_current_states, never machine_events
        $eventQueries = array_filter($queries, fn ($q) => str_contains($q['query'], 'machine_events'));
        expect($eventQueries)->toBeEmpty('get() should not query machine_events');
        expect($results)->toHaveCount(2);
    });

    test('count() executes single query without hydration', function (): void {
        createPersistedQBMachine('idle');
        createPersistedQBMachine('active');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $count = QueryBuilderTestMachine::query()->inState('idle')->count();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // count() should be a single query with COUNT(DISTINCT)
        expect($queries)->toHaveCount(1);
        expect($queries[0]['query'])->toContain('count');
        expect($count)->toBe(1);
    });

    test('pluckMachineIds() does not query machine_events', function (): void {
        createPersistedQBMachine('idle');
        createPersistedQBMachine('active');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $ids = QueryBuilderTestMachine::query()->inState('idle')->pluckMachineIds();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $eventQueries = array_filter($queries, fn ($q) => str_contains($q['query'], 'machine_events'));
        expect($eventQueries)->toBeEmpty('pluckMachineIds() should not query machine_events');
        expect($ids)->toHaveCount(1);
    });

    test('accessing machineId/stateId/stateEnteredAt on results does not trigger restore', function (): void {
        createPersistedQBMachine('idle');

        $results = QueryBuilderTestMachine::query()->inState('idle')->get();

        DB::enableQueryLog();
        DB::flushQueryLog();

        // Access all lightweight properties
        foreach ($results as $result) {
            $_ = $result->machineId;
            $_ = $result->stateId;
            $_ = $result->stateEnteredAt;
            $_ = $result->stateIds;
        }

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // No queries should have been executed — properties are pre-loaded
        expect($queries)->toBeEmpty('Accessing result properties should not trigger any queries');
    });

    test('machine() triggers restore but only once (cached)', function (): void {
        createPersistedQBMachine('active');

        $result = QueryBuilderTestMachine::query()->inState('active')->first();

        DB::enableQueryLog();
        DB::flushQueryLog();

        // First call — should trigger machine_events query
        $machine1 = $result->machine();

        $firstCallQueries = count(DB::getQueryLog());
        expect($firstCallQueries)->toBeGreaterThan(0, 'First machine() call should query machine_events');

        DB::flushQueryLog();

        // Second call — should be cached, no queries
        $machine2 = $result->machine();

        $secondCallQueries = DB::getQueryLog();
        expect($secondCallQueries)->toBeEmpty('Second machine() call should be cached');
        expect($machine1)->toBe($machine2); // same object reference
    });

    test('listing 20 results does not restore any machines', function (): void {
        for ($i = 0; $i < 20; $i++) {
            createPersistedQBMachine('idle');
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        $results = QueryBuilderTestMachine::query()
            ->inState('idle')
            ->get();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect($results)->toHaveCount(20);

        // Should only have queries against machine_current_states (2: buildIds + hydrate)
        // Never machine_events
        $eventQueries = array_filter($queries, fn ($q) => str_contains($q['query'], 'machine_events'));
        expect($eventQueries)->toBeEmpty('Listing 20 results should not trigger any machine_events queries');

        // Should not have 20+ queries (N+1 would mean 20 individual restores)
        expect(count($queries))->toBeLessThanOrEqual(3, 'Should be at most 3 queries (ids + hydrate + machine_class filter)');
    });
});
