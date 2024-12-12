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

test('validates condition arrays in transitions', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'      => 'machine',
        'initial' => 'state_a',
        'states'  => [
            'state_a' => [
                'on' => [
                    'EVENT' => [
                        'not_an_array', // Invalid condition - should be an array
                        ['target' => 'state_b'],
                    ],
                ],
            ],
        ],
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: "State 'state_a' has invalid condition in transition for event 'EVENT'. Each condition must be an array with target/guards/actions."
    );
});

test('validates guards configuration in transitions', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'      => 'machine',
        'initial' => 'state_a',
        'states'  => [
            'state_a' => [
                'on' => [
                    'EVENT' => [
                        'target' => 'state_b',
                        'guards' => true, // Guards should be an array or a string
                    ],
                ],
            ],
        ],
    ]))->toThrow(
        InvalidArgumentException::class,
        "State 'state_a' has invalid guards configuration for event 'EVENT'. Guards must be an array or string."
    );
});

test('validates actions configuration in transitions', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'      => 'machine',
        'initial' => 'state_a',
        'states'  => [
            'state_a' => [
                'on' => [
                    'EVENT' => [
                        'target'  => 'state_b',
                        'actions' => true, // Actions should be an array or string
                    ],
                ],
            ],
        ],
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: "State 'state_a' has invalid actions configuration for event 'EVENT'. Actions must be an array or string."
    );
});

test('validates calculators configuration in transitions', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'      => 'machine',
        'initial' => 'state_a',
        'states'  => [
            'state_a' => [
                'on' => [
                    'EVENT' => [
                        'target'      => 'state_b',
                        'calculators' => 123, // Calculators should be an array or string
                    ],
                ],
            ],
        ],
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: "State 'state_a' has invalid calculators configuration for event 'EVENT'. Calculators must be an array or string."
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

test('validates entry and exit actions are arrays or strings', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'      => 'machine',
        'initial' => 'state_a',
        'states'  => [
            'state_a' => [
                'entry' => true, // Should be an array or a string
                'exit'  => 123, // Should be an array or a string
            ],
        ],
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: "State 'state_a' has invalid entry/exit actions configuration. Actions must be an array or string."
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

test('validates default condition must be last in guarded transitions', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'     => 'machine',
        'states' => [
            'state_a' => [
                'on' => [
                    'EVENT' => [
                        [
                            'target' => 'state_b', // Default condition (no guards)
                        ],
                        [
                            'target' => 'state_c',
                            'guards' => 'someGuard',
                        ],
                    ],
                ],
            ],
        ],
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: "State 'state_a' has invalid conditions order for event 'EVENT'. Default condition (no guards) must be the last condition."
    );
});

test('validates target is required in guarded transitions', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'     => 'machine',
        'states' => [
            'state_a' => [
                'on' => [
                    'EVENT' => [
                        [
                            'guards' => 'someGuard', // Missing target
                        ],
                    ],
                ],
            ],
        ],
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: "State 'state_a' has invalid condition at index 0 for event 'EVENT'. Each condition must have a target."
    );
});

test('accepts valid guarded transitions with multiple conditions', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'     => 'machine',
        'states' => [
            'state_a' => [
                'on' => [
                    'EVENT' => [
                        [
                            'target'      => 'state_b',
                            'guards'      => ['guard1', 'guard2'],
                            'actions'     => 'action1',
                            'calculators' => ['calc1', 'calc2'],
                        ],
                        [
                            'target' => 'state_c',
                            'guards' => 'guard3',
                        ],
                        [
                            'target' => 'state_d', // Default condition
                        ],
                    ],
                ],
            ],
        ],
    ]))->not->toThrow(exception: InvalidArgumentException::class);
});
