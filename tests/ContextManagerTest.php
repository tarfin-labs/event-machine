<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext;

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

test('machine definition with no context', function (): void {
    $machineDefinition = MachineDefinition::define();

    expect($machineDefinition->context)->toBeInstanceOf(ContextManager::class);
});

test('machine definition with context as only data', function (): void {
    $machineDefinition = MachineDefinition::define([
        'context' => [
            'key1' => 'value1',
            'key2' => 'value2',
        ],
    ]);

    $context = $machineDefinition->context;
    expect($context)->toBeInstanceOf(ContextManager::class);
    expect($context->get(key: 'key1'))->toBe('value1');
    expect($context->get(key: 'key2'))->toBe('value2');
});

test('machine definition with context behavior', function (): void {
    $machineDefinition = MachineDefinition::define(
        config: [
            'context' => [
                'count' => 1,
            ],
        ],
        behavior: [
            'context' => TrafficLightsContext::class,
        ],
    );

    /** @var TrafficLightsContext $context */
    $context = $machineDefinition->context;

    expect($context)
        ->toBeInstanceOf(TrafficLightsContext::class)
        ->count->toBe(1);
});
