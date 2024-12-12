<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext;

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

test('validates state type values', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'      => 'machine',
        'initial' => 'state_a',
        'states'  => [
            'state_a' => [
                'type' => 'invalid_type', // Type should be 'atomic', 'compound', or 'final'
            ],
        ],
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: "State 'state_a' has invalid type: invalid_type. Allowed types are: atomic, compound, final"
    );
});

test('validates final states have no transitions', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'      => 'machine',
        'initial' => 'state_a',
        'states'  => [
            'state_a' => [
                'type' => 'final',
                'on'   => [
                    'EVENT' => 'state_b',
                ],
            ],
        ],
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: "Final state 'state_a' cannot have transitions"
    );
});

test('validates final states have no child states', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'      => 'machine',
        'initial' => 'state_a',
        'states'  => [
            'state_a' => [
                'type'   => 'final',
                'states' => [
                    'child' => [],
                ],
            ],
        ],
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: "Final state 'state_a' cannot have child states"
    );
});

test('accepts valid state configuration with all possible features', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'      => 'machine',
        'version' => '1.0.0',
        'initial' => 'state_a',
        'context' => TrafficLightsContext::class,
        'states'  => [
            'state_a' => [
                'type'        => 'compound',
                'initial'     => 'child_a',
                'entry'       => ['entryAction1', 'entryAction2'],
                'exit'        => 'exitAction',
                'meta'        => ['some' => 'data'],
                'description' => 'A compound state',
                'on'          => [
                    'EVENT' => [
                        'target'      => 'state_b',
                        'guards'      => ['guard1', 'guard2'],
                        'actions'     => ['action1', 'action2'],
                        'calculators' => ['calc1', 'calc2'],
                        'description' => 'Transition to state B',
                    ],
                    '@always' => [
                        [
                            'target'      => 'state_b',
                            'guards'      => 'guard1',
                            'description' => 'Always transition when guard passes',
                        ],
                        [
                            'target'      => 'state_c',
                            'description' => 'Default always transition',
                        ],
                    ],
                ],
                'states' => [
                    'child_a' => [
                        'type' => 'atomic',
                    ],
                    'child_b' => [
                        'type' => 'final',
                    ],
                ],
            ],
            'state_b' => [],
            'state_c' => [],
        ],
    ]))->not->toThrow(exception: InvalidArgumentException::class);
});

test('normalizes string behaviors to arrays', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'     => 'machine',
        'states' => [
            'state_a' => [
                'on' => [
                    'EVENT' => [
                        'target'      => 'state_b',
                        'guards'      => 'singleGuard',
                        'actions'     => 'singleAction',
                        'calculators' => 'singleCalculator',
                    ],
                ],
            ],
        ],
    ]))->not->toThrow(exception: InvalidArgumentException::class);
});

test('validates empty guarded transitions array', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'     => 'machine',
        'states' => [
            'state_a' => [
                'on' => [
                    'EVENT' => [], // Empty conditions array
                ],
            ],
        ],
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: "State 'state_a' has empty conditions array for event 'EVENT'. Guarded transitions must have at least one condition."
    );
});
