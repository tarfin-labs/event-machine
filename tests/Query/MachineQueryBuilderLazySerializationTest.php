<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\QueryBuilderTestMachine;

describe('Lazy Restore', function (): void {
    test('machine() restores full Machine instance', function (): void {
        createPersistedQBMachine('active');

        $result = QueryBuilderTestMachine::query()
            ->inState('active')
            ->first();

        $machine = $result->machine();

        expect($machine)->toBeInstanceOf(Machine::class);
        expect($machine->state->matches('active'))->toBeTrue();
    });

    test('machine() returns cached instance on second call', function (): void {
        createPersistedQBMachine('idle');

        $result = QueryBuilderTestMachine::query()
            ->inState('idle')
            ->first();

        $first  = $result->machine();
        $second = $result->machine();

        expect($first)->toBe($second); // same object reference
    });

    test('context() returns ContextManager', function (): void {
        createPersistedQBMachine('idle');

        $result = QueryBuilderTestMachine::query()
            ->inState('idle')
            ->first();

        expect($result->context())->toBeInstanceOf(ContextManager::class);
    });
});

describe('Serialization', function (): void {
    test('toArray() returns correct structure', function (): void {
        createPersistedQBMachine('idle');

        $result = QueryBuilderTestMachine::query()
            ->inState('idle')
            ->first();

        $array = $result->toArray();

        expect($array)->toHaveKeys(['machine_id', 'state_id', 'state_ids', 'state_entered_at']);
        expect($array['machine_id'])->toBeString();
        expect($array['state_id'])->toContain('idle');
        expect($array['state_ids'])->toBeArray();
        expect($array['state_entered_at'])->toBeString();
    });

    test('jsonSerialize() matches toArray()', function (): void {
        createPersistedQBMachine('idle');

        $result = QueryBuilderTestMachine::query()
            ->inState('idle')
            ->first();

        expect($result->jsonSerialize())->toBe($result->toArray());
    });

    test('json_encode produces valid JSON', function (): void {
        createPersistedQBMachine('idle');

        $result = QueryBuilderTestMachine::query()
            ->inState('idle')
            ->first();

        $json = json_encode($result, JSON_THROW_ON_ERROR);

        expect($json)->toBeString();

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        expect($decoded)->toHaveKey('machine_id');
        expect($decoded)->toHaveKey('state_ids');
    });
});
