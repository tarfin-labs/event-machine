<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

test('validates root level configuration keys', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'              => 'machine',
        'invalid_key'     => 'value',
        'another_invalid' => 'value',
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: 'Invalid root level configuration keys: invalid_key, another_invalid. Allowed keys are: id, version, initial, context, states, on, type, meta, entry, exit, description, scenarios_enabled, should_persist, delimiter'
    );
});

test('accepts valid root level configuration', function (): void {
    // HINT: This test should contain all possible root level configuration keys
    expect(fn () => MachineDefinition::define([
        'id'                => 'machine',
        'version'           => '1.0.0',
        'initial'           => 'state_a',
        'context'           => ['some' => 'data'],
        'scenarios_enabled' => true,
        'should_persist'    => true,
        'delimiter'         => '.',
        'states'            => [
            'state_a' => [],
        ],
    ]))->not->toThrow(exception: InvalidArgumentException::class);
});

test('accepts machine with root level transitions', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'      => 'machine',
        'initial' => 'state_a',
        'on'      => [
            'GLOBAL_EVENT' => 'state_b',
        ],
        'states' => [
            'state_a' => [],
            'state_b' => [],
        ],
    ]))->not->toThrow(InvalidArgumentException::class);
});

test('transitions must be defined under the on key', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'      => 'machine',
        'initial' => 'check',
        'states'  => [
            'check' => [
                '@always' => [ // Transition defined directly under state
                    'target' => 'next',
                ],
            ],
        ],
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: "State 'check' has transitions defined directly. All transitions including '@always' must be defined under the 'on' key."
    );
});

test('validates on property is an array', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'      => 'machine',
        'initial' => 'state_a',
        'states'  => [
            'state_a' => [
                'on' => 'invalid_string', // 'on' should be an array
            ],
        ],
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: "State 'state_a' has invalid 'on' definition. 'on' must be an array of transitions."
    );
});

test('validates transition target is either string or array', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'      => 'machine',
        'initial' => 'state_a',
        'states'  => [
            'state_a' => [
                'on' => [
                    'EVENT' => true, // Invalid transition definition
                ],
            ],
        ],
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: "State 'state_a' has invalid transition for event 'EVENT'. Transition must be a string (target state) or an array (transition config)."
    );
});
test('validates allowed keys in transition config', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'      => 'machine',
        'initial' => 'state_a',
        'states'  => [
            'state_a' => [
                'on' => [
                    'EVENT' => [
                        'target'          => 'state_b',
                        'invalid_key'     => 'value',
                        'another_invalid' => 'value',
                    ],
                ],
            ],
        ],
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: "State 'state_a' has invalid keys in transition config for event 'EVENT': invalid_key, another_invalid. Allowed keys are: target, guards, actions, description, calculators"
    );
});
