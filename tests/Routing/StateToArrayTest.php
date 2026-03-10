<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;

// === toArray ===

test('toArray returns value and context arrays', function (): void {
    $state = State::forTesting(
        context: ['total' => 100, 'name' => 'test'],
    );

    $array = $state->toArray();

    expect($array)->toHaveKeys(['value', 'context'])
        ->and($array['value'])->toBe([])
        ->and($array['context'])->toBe(['data' => ['total' => 100, 'name' => 'test']]);
});

test('toArray returns state value when currentStateDefinition is set', function (): void {
    $definition = \Tarfinlabs\EventMachine\Definition\MachineDefinition::define(
        config: [
            'id'      => 'toarray_test',
            'initial' => 'idle',
            'context' => ['count' => 0],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'active'],
                ],
                'active' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'events' => [
                'GO' => \Tarfinlabs\EventMachine\Tests\Stubs\Events\SimpleEvent::class,
            ],
        ],
    );

    $stateDefinition = $definition->idMap['toarray_test.idle'];

    $state = State::forTesting(
        context: ['count' => 0],
        currentStateDefinition: $stateDefinition,
    );

    $array = $state->toArray();

    expect($array['value'])->toBe(['toarray_test.idle'])
        ->and($array['context'])->toBe(['data' => ['count' => 0]]);
});

// === jsonSerialize ===

test('jsonSerialize delegates to toArray', function (): void {
    $state = State::forTesting(
        context: ['key' => 'value'],
    );

    expect($state->jsonSerialize())->toBe($state->toArray());
});

test('json_encode uses jsonSerialize output', function (): void {
    $state = State::forTesting(
        context: ['amount' => 42],
    );

    $json    = json_encode($state, JSON_THROW_ON_ERROR);
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toBe($state->toArray());
});

// === Empty state ===

test('toArray with no currentStateDefinition returns empty value array', function (): void {
    $state = State::forTesting(context: []);

    expect($state->value)->toBe([])
        ->and($state->toArray()['value'])->toBe([]);
});

test('toArray with empty context returns empty context array', function (): void {
    $state = State::forTesting(context: []);

    expect($state->toArray()['context'])->toBe(['data' => []]);
});
