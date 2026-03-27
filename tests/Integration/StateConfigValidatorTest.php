<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use Tarfinlabs\EventMachine\StateConfigValidator;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\RecordAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\GuardedMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\MultiOutcomeChildMachine;

test('validates root level configuration keys', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'              => 'machine',
        'invalid_key'     => 'value',
        'another_invalid' => 'value',
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: 'Invalid root level configuration keys: invalid_key, another_invalid. Allowed keys are: id, version, initial, status_events, context, states, on, type, meta, entry, exit, description, scenarios_enabled, should_persist, delimiter'
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
                'type' => 'invalid_type', // Type should be 'atomic', 'compound', 'parallel', or 'final'
            ],
        ],
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: "State 'state_a' has invalid type: invalid_type. Allowed types are: atomic, compound, parallel, final"
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

test('empty array is treated as targetless transition, not empty guarded transition', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'     => 'machine',
        'states' => [
            'state_a' => [
                'on' => [
                    'EVENT' => [], // Targetless transition (stay in current state)
                ],
            ],
        ],
    ]))->not->toThrow(exception: InvalidArgumentException::class);
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

test('guarded transitions can run actions without changing state when no target is specified', function (): void {
    // 1.1. Arrange
    RecordAction::reset();
    $machine = GuardedMachine::create();

    // 1.2. Act
    $state = $machine->send(['type' => 'CHECK']);

    // 1.3. Assert
    expect($state->matches('processed'))->toBeTrue()
        ->and(RecordAction::wasExecuted())->toBeFalse();

    // 2.1. Arrange
    RecordAction::reset();
    $machine = GuardedMachine::create();

    // 2.2. Act
    $machine->send(['type' => 'INCREASE']);
    $state = $machine->send(['type' => 'CHECK']);

    // 2.3. Assert
    expect($state->matches('active'))->toBeTrue()
        ->and(RecordAction::wasExecuted())->toBeTrue();
});

// region @done/@fail Validation Tests

test('it accepts string @done configuration', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'      => 'machine',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 'completed',
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => ['on' => ['DONE_A' => 'finished']],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]))->not->toThrow(InvalidArgumentException::class);
});

test('it accepts array @done with target and actions', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'      => 'machine',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => ['target' => 'completed', 'actions' => 'logAction'],
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => ['on' => ['DONE_A' => 'finished']],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]))->not->toThrow(InvalidArgumentException::class);
});

test('it accepts conditional @done with guards', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'      => 'machine',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'  => 'parallel',
                '@done' => [
                    ['target' => 'approved', 'guards' => 'isAllPassedGuard'],
                    ['target' => 'manual_review'],
                ],
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => ['on' => ['DONE_A' => 'finished']],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'approved'      => ['type' => 'final'],
            'manual_review' => ['type' => 'final'],
        ],
    ]))->not->toThrow(InvalidArgumentException::class);
});

test('it rejects @done with default branch not last', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'      => 'machine',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'  => 'parallel',
                '@done' => [
                    ['target' => 'manual_review'], // Default (no guards) NOT last
                    ['target' => 'approved', 'guards' => 'isAllPassedGuard'],
                ],
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => ['on' => ['DONE_A' => 'finished']],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'approved'      => ['type' => 'final'],
            'manual_review' => ['type' => 'final'],
        ],
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: "State 'processing' has invalid conditions order for event '@done'. Default condition (no guards) must be the last condition."
    );
});

test('it rejects @done with invalid keys in branch', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'      => 'machine',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'  => 'parallel',
                '@done' => [
                    'target'      => 'completed',
                    'invalid_key' => 'value',
                ],
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => ['on' => ['DONE_A' => 'finished']],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: "State 'processing' has invalid keys in transition config for event '@done': invalid_key. Allowed keys are: target, guards, actions, description, calculators"
    );
});

test('it accepts conditional @fail with guards', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'      => 'machine',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'  => 'parallel',
                '@fail' => [
                    ['target' => 'retrying', 'guards' => 'canRetryGuard', 'actions' => 'incrementRetryAction'],
                    ['target' => 'failed', 'actions' => 'sendAlertAction'],
                ],
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => ['on' => ['DONE_A' => 'finished']],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'retrying' => ['type' => 'final'],
            'failed'   => ['type' => 'final'],
        ],
    ]))->not->toThrow(InvalidArgumentException::class);
});

test('it rejects invalid @done format', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'      => 'machine',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 42, // Integer — not valid
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => ['on' => ['DONE_A' => 'finished']],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: "State 'processing' has invalid '@done' configuration. Must be a string or array."
    );
});

test('it rejects invalid @fail format', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'      => 'machine',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@fail'  => 42, // Integer — not valid
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => ['on' => ['DONE_A' => 'finished']],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'failed' => ['type' => 'final'],
        ],
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: "State 'processing' has invalid '@fail' configuration. Must be a string or array."
    );
});

test('it accepts @done on compound state with guards', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'      => 'machine',
        'initial' => 'verification',
        'states'  => [
            'verification' => [
                '@done' => [
                    ['target' => 'approved', 'guards' => 'isValidGuard'],
                    ['target' => 'rejected'],
                ],
                'initial' => 'checking',
                'states'  => [
                    'checking' => ['on' => ['CHECK' => 'done']],
                    'done'     => ['type' => 'final'],
                ],
            ],
            'approved' => ['type' => 'final'],
            'rejected' => ['type' => 'final'],
        ],
    ]))->not->toThrow(InvalidArgumentException::class);
});

test('it rejects @fail with default branch not last', function (): void {
    expect(fn () => MachineDefinition::define([
        'id'      => 'machine',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'  => 'parallel',
                '@fail' => [
                    ['target' => 'failed'],   // Default (no guards) NOT last
                    ['target' => 'retrying', 'guards' => 'canRetryGuard'],
                ],
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => ['on' => ['DONE_A' => 'finished']],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'retrying' => ['type' => 'final'],
            'failed'   => ['type' => 'final'],
        ],
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: "State 'processing' has invalid conditions order for event '@fail'. Default condition (no guards) must be the last condition."
    );
});

// endregion

// region @done.{state} Validation

it('accepts @done.{state} keys as valid state keys (T20)', function (): void {
    expect(fn () => StateConfigValidator::validate([
        'id'      => 'parent',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'machine'        => MultiOutcomeChildMachine::class,
                '@done.approved' => 'completed',
                '@done.rejected' => 'declined',
                '@done.expired'  => 'declined',
            ],
            'completed' => ['type' => 'final'],
            'declined'  => ['type' => 'final'],
        ],
    ]))->not->toThrow(InvalidArgumentException::class);
});

it('validates @done.{state} values as transition configs (T21)', function (): void {
    expect(fn () => StateConfigValidator::validate([
        'id'      => 'parent',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'machine'        => MultiOutcomeChildMachine::class,
                '@done.approved' => 123,
                '@done'          => 'fallback',
            ],
            'completed' => ['type' => 'final'],
            'fallback'  => ['type' => 'final'],
        ],
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: "has invalid '@done.approved' configuration"
    );
});

it('validates @done.{state} guarded branches order (T22)', function (): void {
    // Valid: guarded first, default last
    expect(fn () => StateConfigValidator::validate([
        'id'      => 'parent',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'machine'        => MultiOutcomeChildMachine::class,
                '@done.approved' => [
                    ['target' => 'vip', 'guards' => 'isVipGuard'],
                    ['target' => 'standard'],
                ],
                '@done' => 'fallback',
            ],
            'vip'      => ['type' => 'final'],
            'standard' => ['type' => 'final'],
            'fallback' => ['type' => 'final'],
        ],
    ]))->not->toThrow(InvalidArgumentException::class);

    // Invalid: default branch not last
    expect(fn () => StateConfigValidator::validate([
        'id'      => 'parent',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'machine'        => MultiOutcomeChildMachine::class,
                '@done.approved' => [
                    ['target' => 'standard'],
                    ['target' => 'vip', 'guards' => 'isVipGuard'],
                ],
                '@done' => 'fallback',
            ],
            'vip'      => ['type' => 'final'],
            'standard' => ['type' => 'final'],
            'fallback' => ['type' => 'final'],
        ],
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: 'Default condition (no guards) must be the last condition'
    );
});

it('rejects @done. with empty suffix (T29)', function (): void {
    expect(fn () => StateConfigValidator::validate([
        'id'      => 'parent',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'machine' => MultiOutcomeChildMachine::class,
                '@done.'  => 'completed',
            ],
            'completed' => ['type' => 'final'],
        ],
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: 'final state name after the dot cannot be empty'
    );
});

// region @done.{state} Coverage Validation

it('passes when all child final states are covered by @done.{state} (T23)', function (): void {
    expect(fn () => StateConfigValidator::validate([
        'id'      => 'parent',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'machine'        => MultiOutcomeChildMachine::class,
                '@done.approved' => 'completed',
                '@done.rejected' => 'declined',
                '@done.expired'  => 'timed_out',
            ],
            'completed' => ['type' => 'final'],
            'declined'  => ['type' => 'final'],
            'timed_out' => ['type' => 'final'],
        ],
    ]))->not->toThrow(InvalidArgumentException::class);
});

it('throws when child final states are uncovered without catch-all (T24)', function (): void {
    expect(fn () => StateConfigValidator::validate([
        'id'      => 'parent',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'machine'        => MultiOutcomeChildMachine::class,
                '@done.approved' => 'completed',
                '@done.rejected' => 'declined',
                // missing @done.expired and no @done catch-all
            ],
            'completed' => ['type' => 'final'],
            'declined'  => ['type' => 'final'],
        ],
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: 'uncovered final states: expired'
    );
});

it('passes when catch-all @done covers remaining final states (T25)', function (): void {
    expect(fn () => StateConfigValidator::validate([
        'id'      => 'parent',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'machine'        => MultiOutcomeChildMachine::class,
                '@done.approved' => 'completed',
                '@done'          => 'fallback',
            ],
            'completed' => ['type' => 'final'],
            'fallback'  => ['type' => 'final'],
        ],
    ]))->not->toThrow(InvalidArgumentException::class);
});

it('passes with only @done and no dot notation — backward compatible (T26)', function (): void {
    expect(fn () => StateConfigValidator::validate([
        'id'      => 'parent',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'machine' => MultiOutcomeChildMachine::class,
                '@done'   => 'completed',
            ],
            'completed' => ['type' => 'final'],
        ],
    ]))->not->toThrow(InvalidArgumentException::class);
});

it('skips coverage validation when child class does not exist (T27)', function (): void {
    expect(fn () => StateConfigValidator::validate([
        'id'      => 'parent',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'machine'        => 'NonExistent\\Machine',
                '@done.approved' => 'completed',
            ],
            'completed' => ['type' => 'final'],
        ],
    ]))->not->toThrow(InvalidArgumentException::class);
});

// endregion @done.{state} Coverage Validation

// region @done.{state} Fire-and-Forget + Non-Final

it('@done.{state} + queue is NOT fire-and-forget — @fail allowed (T28)', function (): void {
    expect(fn () => StateConfigValidator::validate([
        'id'      => 'parent',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'machine'        => MultiOutcomeChildMachine::class,
                'queue'          => true,
                '@done.approved' => 'completed',
                '@done.rejected' => 'declined',
                '@done.expired'  => 'declined',
                '@fail'          => 'error',
            ],
            'completed' => ['type' => 'final'],
            'declined'  => ['type' => 'final'],
            'error'     => ['type' => 'final'],
        ],
    ]))->not->toThrow(InvalidArgumentException::class);
});

it('@done.{state} referencing non-final child state is flagged by coverage validation (T30)', function (): void {
    // MultiOutcomeChildMachine has 'processing' (non-final) + 3 final states
    // @done.processing references a non-final state — coverage validation
    // won't find 'processing' in the final states list, so it's treated as
    // an extra key that doesn't cover any final state. The uncovered final
    // states will cause the validation to throw.
    expect(fn () => StateConfigValidator::validate([
        'id'      => 'parent',
        'initial' => 'delegating',
        'states'  => [
            'delegating' => [
                'machine'          => MultiOutcomeChildMachine::class,
                '@done.processing' => 'error',
                '@done.approved'   => 'completed',
                // missing rejected and expired → coverage fails
            ],
            'completed' => ['type' => 'final'],
            'error'     => ['type' => 'final'],
        ],
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: 'uncovered final states'
    );
});

it('rejects @done.{state} keys on state without machine delegation', function (): void {
    expect(fn () => StateConfigValidator::validate([
        'id'      => 'parent',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                '@done.approved' => 'completed',
                'on'             => ['GO' => 'completed'],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]))->toThrow(
        exception: InvalidArgumentException::class,
        exceptionMessage: '@done.{state}'
    );
});

// endregion @done.{state} Fire-and-Forget + Non-Final

// endregion @done.{state} Validation
