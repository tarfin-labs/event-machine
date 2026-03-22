<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Query\MachineQueryResult;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\QueryBuilderTestMachine;

/**
 * Helper: create and persist a QueryBuilderTestMachine in the given state.
 */
function createPersistedQBMachine(string $targetState = 'idle'): Machine
{
    $machine = QueryBuilderTestMachine::create();
    $machine->persist();

    if ($targetState === 'active') {
        $machine->send(['type' => 'START']);
    } elseif ($targetState === 'completed') {
        $machine->send(['type' => 'START']);
        $machine->send(['type' => 'FINISH']);
    }

    return $machine;
}

describe('Basic Querying', function (): void {
    test('query returns results for machines in a given state', function (): void {
        $idleMachine = createPersistedQBMachine('idle');
        createPersistedQBMachine('active');

        $results = QueryBuilderTestMachine::query()
            ->inState('idle')
            ->get();

        expect($results)->toHaveCount(1);
        expect($results->first())->toBeInstanceOf(MachineQueryResult::class);
        expect($results->first()->machineId)->toBe(
            $idleMachine->state->history->first()->root_event_id
        );
    });

    test('result has correct properties', function (): void {
        createPersistedQBMachine('idle');

        $result = QueryBuilderTestMachine::query()
            ->inState('idle')
            ->first();

        expect($result)->not->toBeNull();
        expect($result->machineId)->toBeString();
        expect($result->stateId)->toContain('idle');
        expect($result->stateEnteredAt)->toBeInstanceOf(Carbon::class);
        expect($result->stateIds)->toBeArray();
        expect($result->stateIds)->toHaveCount(1);
    });

    test('query returns empty collection when no matches', function (): void {
        createPersistedQBMachine('idle');

        $results = QueryBuilderTestMachine::query()
            ->inState('active')
            ->get();

        expect($results)->toBeEmpty();
    });
});

describe('State Matching', function (): void {
    test('leaf match resolves short state name to full ID', function (): void {
        createPersistedQBMachine('active');

        $results = QueryBuilderTestMachine::query()
            ->inState('active')
            ->get();

        expect($results)->toHaveCount(1);
    });

    test('exact match works with full dot-notation state ID', function (): void {
        createPersistedQBMachine('active');

        $results = QueryBuilderTestMachine::query()
            ->inState('qb_test.active')
            ->get();

        expect($results)->toHaveCount(1);
    });

    test('parent match returns instances in any descendant state', function (): void {
        createPersistedQBMachine('idle');
        createPersistedQBMachine('active');

        // 'qb_test' is the root COMPOUND state — should match all descendants
        $results = QueryBuilderTestMachine::query()
            ->inState('qb_test')
            ->get();

        expect($results)->toHaveCount(2);
    });

    test('wildcard match works with asterisk pattern', function (): void {
        createPersistedQBMachine('idle');

        $results = QueryBuilderTestMachine::query()
            ->inState('qb_test.*')
            ->get();

        expect($results)->toHaveCount(1);
    });

    test('invalid state name throws InvalidArgumentException', function (): void {
        createPersistedQBMachine('idle');

        QueryBuilderTestMachine::query()
            ->inState('nonexistent_state')
            ->get();
    })->throws(InvalidArgumentException::class);
});
