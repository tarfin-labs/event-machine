<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\Stubs\Machines\QueryBuilderTestMachine;

describe('Edge Cases', function (): void {
    test('count returns 0 when no instances match', function (): void {
        $count = QueryBuilderTestMachine::query()
            ->inState('idle')
            ->count();

        expect($count)->toBe(0);
    });

    test('first returns null when no instances match', function (): void {
        $result = QueryBuilderTestMachine::query()
            ->inState('idle')
            ->first();

        expect($result)->toBeNull();
    });

    test('get returns empty collection when no instances exist', function (): void {
        $results = QueryBuilderTestMachine::query()
            ->inState('idle')
            ->get();

        expect($results)->toBeEmpty();
    });

    test('pluckMachineIds returns root_event_id strings', function (): void {
        $machine = createPersistedQBMachine('idle');

        $ids = QueryBuilderTestMachine::query()
            ->inState('idle')
            ->pluckMachineIds();

        expect($ids)->toHaveCount(1);
        expect($ids->first())->toBe(
            $machine->state->history->first()->root_event_id
        );
    });

    test('invalid state name throws InvalidArgumentException', function (): void {
        QueryBuilderTestMachine::query()
            ->inState('does_not_exist');
    })->throws(InvalidArgumentException::class);

    test('multiple machines can be queried and counted', function (): void {
        createPersistedQBMachine('idle');
        createPersistedQBMachine('idle');
        createPersistedQBMachine('active');

        expect(QueryBuilderTestMachine::query()->inState('idle')->count())->toBe(2);
        expect(QueryBuilderTestMachine::query()->inState('active')->count())->toBe(1);
    });
});
