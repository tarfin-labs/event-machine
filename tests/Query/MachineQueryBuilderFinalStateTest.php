<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\Stubs\Machines\QueryBuilderTestMachine;

describe('Final State Filtering', function (): void {
    test('active() returns only non-final instances', function (): void {
        createPersistedQBMachine('idle');
        createPersistedQBMachine('active');
        createPersistedQBMachine('completed');

        $results = QueryBuilderTestMachine::query()
            ->active()
            ->get();

        expect($results)->toHaveCount(2);
    });

    test('notInFinalState() excludes final state instances', function (): void {
        createPersistedQBMachine('idle');
        createPersistedQBMachine('completed');

        $results = QueryBuilderTestMachine::query()
            ->notInFinalState()
            ->get();

        expect($results)->toHaveCount(1);
        expect($results->first()->stateId)->toContain('idle');
    });

    test('inFinalState() returns only final state instances', function (): void {
        createPersistedQBMachine('idle');
        createPersistedQBMachine('active');
        createPersistedQBMachine('completed');

        $results = QueryBuilderTestMachine::query()
            ->inFinalState()
            ->get();

        expect($results)->toHaveCount(1);
        expect($results->first()->stateId)->toContain('completed');
    });
});
