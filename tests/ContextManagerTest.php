<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AbcMachine;
use Tarfinlabs\EventMachine\Exceptions\MachineContextValidationException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

it('can initialize an empty context manager', function (): void {
    $context = new ContextManager();

    expect($context)->toBeInstanceOf(ContextManager::class);
});

it('can set and get context manager data', function (): void {
    $context = new ContextManager();

    $context->set(key: 'key1', value: 'value1');
    $context->set(key: 'key2', value: 'value2');

    expect($context->get(key: 'key1'))->toBe('value1');
    expect($context->get(key: 'key2'))->toBe('value2');
});

it('returns null for non-existent keys', function (): void {
    $context = new ContextManager();

    expect($context->get(key: 'non_existent_key'))->toBeNull();
});

it('can check if a key exists', function (): void {
    $context = new ContextManager();
    $context->set(key: 'key1', value: 'value1');

    expect($context->has(key: 'key1'))->toBeTrue();
    expect($context->has(key: 'non_existent_key'))->toBeFalse();
});

it('can remove a key from context data', function (): void {
    $context = new ContextManager();

    $context->set(key: 'key1', value: 'value1');
    $context->set(key: 'key2', value: 'value2');
    $context->remove(key: 'key1');

    expect($context->has(key: 'key1'))->toBeFalse();
    expect($context->has(key: 'key2'))->toBeTrue();
});

it('can initialize context data with an array', function (): void {
    $initialData = ['key1' => 'value1', 'key2' => 'value2'];
    $context     = new ContextManager($initialData);

    expect($context->get(key: 'key1'))->toBe('value1');
    expect($context->get(key: 'key2'))->toBe('value2');
});

it('can convert context data to an array', function (): void {
    $initialData = ['key1' => 'value1', 'key2' => 'value2'];
    $context     = new ContextManager($initialData);

    $contextArray = $context->toArray();

    expect($contextArray)->toBeArray();
    expect($contextArray['data'])->toHaveCount(2);
    expect($contextArray['data']['key1'])->toBe('value1');
    expect($contextArray['data']['key2'])->toBe('value2');
});

it('can handle edge cases with empty keys and values', function (): void {
    $context = new ContextManager();

    $context->set(key: '', value: 'empty_key_value');
    $context->set(key: 'empty_value_key', value: '');

    expect($context->get(key: ''))->toBe('empty_key_value');
    expect($context->get(key: 'empty_value_key'))->toBe('');

    expect($context->has(key: ''))->toBeTrue();
    expect($context->has(key: 'empty_value_key'))->toBeTrue();

    $context->remove(key: '');
    expect($context->has(key: ''))->toBeFalse();
    expect($context->has(key: 'empty_value_key'))->toBeTrue();

    $contextArray = $context->toArray();
    expect($contextArray)->toHaveCount(1);
    expect($contextArray['data']['empty_value_key'])->toBe('');
});

test('TrafficLightsMachine transitions between states using EventMachine', function (): void {
    $machineDefinition = TrafficLightsMachine::build();

    $machineDefinition->transition(event: [
        'type'    => 'SUB_VALUE',
        'payload' => ['value' => 100],
    ]);
})->throws(MachineContextValidationException::class);

test('TrafficLightsContext throws MachineContextValidationException for invalid data', function (): void {
    TrafficLightsContext::validateAndCreate(['count' => -1]);
})->throws(MachineContextValidationException::class);

it('has magic methods', function (): void {
    $machine = AbcMachine::start();

    $machine->state->context->set('key', 'value1');
    expect($machine->state->context->key)->toBe('value1');

    $machine->state->context->key = 'value2';
    expect($machine->state->context->key)->toBe('value2');

    expect(isset($machine->state->context->key))->toBe(true);
    expect(isset($machine->state->context->not_existing_key))->toBe(false);
});

test('abc', function (): void {
    $machine = AbcMachine::start();

    $machine->state->context->set('stringKey', 'stringValue');
    expect($machine->state->context->has(key: 'stringKey', type: 'string'))->toBe(true);

    $machine->state->context->set('intKey', 1);
    expect($machine->state->context->has(key: 'intKey', type: 'integer'))->toBe(true);

    $machine->state->context->set('arrayKey', []);
    expect($machine->state->context->has(key: 'arrayKey', type: 'array'))->toBe(true);

    $machine->state->context->set('objectKey', new MachineEvent());
    expect($machine->state->context->has(key: 'objectKey', type: MachineEvent::class))->toBe(true);
});
