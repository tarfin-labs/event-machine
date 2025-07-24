<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Enums\TransitionProperty;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Definition\TransitionDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AbcMachine;
use Tarfinlabs\EventMachine\Exceptions\NoStateDefinitionFoundException;
use Tarfinlabs\EventMachine\Exceptions\NoTransitionDefinitionFoundException;

// === Basic Transition Definition Tests ===

test('single actions can be defined as strings instead of arrays', function (): void {
    $machine = MachineDefinition::define(config: [
        'states' => [
            'green' => [
                'on' => [
                    'TIMER' => [
                        'target'  => 'yellow',
                        'actions' => 'action1',
                    ],
                ],
            ],
            'yellow' => [],
        ],
    ]);

    $timerTransition = $machine->stateDefinitions['green']->transitionDefinitions['TIMER'];

    expect($timerTransition->branches[0]->actions)->toBe(['action1']);
});

test('transitions can have decriptions', function (): void {
    $machine = MachineDefinition::define(config: [
        'states' => [
            'green' => [
                'on' => [
                    'TIMER' => [
                        'target'      => 'yellow',
                        'description' => 'The timer has expired',
                    ],
                ],
            ],
            'yellow' => [],
        ],
    ]);

    $timerTransition = $machine->stateDefinitions['green']->transitionDefinitions['TIMER'];

    expect($timerTransition->description)->toBe('The timer has expired');
});

test('the transition target can be null', function (): void {
    $machine = MachineDefinition::define([
        'initial' => 'active',
        'states'  => [
            'active' => [
                'on' => [
                    'INC' => [
                        'actions' => ['increment'],
                    ],
                    'DEC' => [
                        'actions' => 'decrement',
                    ],
                ],
            ],
        ],
    ]);

    $incTransition = $machine->stateDefinitions['active']->transitionDefinitions['INC'];
    $decTransition = $machine->stateDefinitions['active']->transitionDefinitions['DEC'];

    expect($incTransition->branches[0]->target)->toBe(null);
    expect($decTransition->branches[0]->target)->toBe(null);
});

test('transitions can have actions', function (): void {
    $machine = MachineDefinition::define(config: [
        'states' => [
            'green' => [
                'on' => [
                    'TIMER' => [
                        'target'  => 'yellow',
                        'actions' => [
                            'action1',
                            'action2',
                        ],
                    ],
                ],
            ],
            'yellow' => [],
        ],
    ]);

    $timerTransition = $machine->stateDefinitions['green']->transitionDefinitions['TIMER'];

    expect($timerTransition->branches[0]->actions)->toBe(['action1', 'action2']);
});

it('throws NoTransitionDefinitionFoundException for unknown events', function (): void {
    $machine = MachineDefinition::define(config: [
        'states' => [
            'green' => [
                'on' => [
                    'TIMER' => [
                        'target' => 'yellow',
                    ],
                ],
            ],
            'yellow' => [],
        ],
    ]);

    expect(fn () => $machine->transition(event: ['type' => 'TIMERX']))
        ->toThrow(
            exception: NoTransitionDefinitionFoundException::class,
            exceptionMessage: "No transition definition found for the event type 'TIMERX' in the current ('machine.green') or parent state definitions. Make sure that a transition is defined for this event type in the current state definition.",
        );
});

it('throws NoStateDefinitionFoundException for unknown states - I', function (): void {
    $machine = MachineDefinition::define(config: [
        'states' => [
            'green' => [
                'on' => [
                    'TIMER' => [
                        'target' => 'no-yellow',
                    ],
                ],
            ],
            'yellow' => [],
        ],
    ]);
})->throws(
    exception: NoStateDefinitionFoundException::class,
    exceptionMessage: "No transition defined in the event machine from state 'machine.green' to state 'no-yellow' for the event type 'TIMER'. Please ensure that a transition for this event type is defined in the current state definition."
);

it('throws NoStateDefinitionFoundException for unknown states - II', function (): void {
    $machine = MachineDefinition::define(config: [
        'states' => [
            'green' => [
                'on' => [
                    'TIMER' => 'no-yellow',
                ],
            ],
            'yellow' => [],
        ],
    ]);
})->throws(
    exception: NoStateDefinitionFoundException::class,
    exceptionMessage: "No transition defined in the event machine from state 'machine.green' to state 'no-yellow' for the event type 'TIMER'. Please ensure that a transition for this event type is defined in the current state definition."
);

test('a guarded transition can have specified guards', function (): void {
    $machine = MachineDefinition::define(config: [
        'states' => [
            'green' => [
                'on' => [
                    'TIMER' => [
                        'target' => 'yellow',
                        'guards' => 'guard1',
                    ],
                ],
            ],
            'yellow' => [],
        ],
    ]);

    /** @var TransitionDefinition $timerTransition */
    $timerTransition = $machine->stateDefinitions['green']->transitionDefinitions['TIMER'];

    expect($timerTransition->branches[0]->guards)->toBe(['guard1']);
});

test('a guarded transition can have multiple specified guards', function (): void {
    $machine = MachineDefinition::define(config: [
        'states' => [
            'green' => [
                'on' => [
                    'TIMER' => [
                        'target' => 'yellow',
                        'guards' => [
                            'guard1',
                            'guard2',
                            'guard3',
                        ],
                    ],
                ],
            ],
            'yellow' => [],
        ],
    ]);

    /** @var TransitionDefinition $timerTransition */
    $timerTransition = $machine->stateDefinitions['green']->transitionDefinitions['TIMER'];

    expect($timerTransition->branches[0]->guards)->toBe([
        'guard1',
        'guard2',
        'guard3',
    ]);
});

test('a guarded transition can have multiple if-else targets', function (): void {
    $machine = MachineDefinition::define(config: [
        'states' => [
            'green' => [
                'on' => [
                    'TIMER' => [
                        [
                            'target' => 'yellow',
                            'guards' => 'guard1',
                        ],
                        [
                            'target' => 'red',
                            'guards' => 'guard2',
                        ],
                        [
                            'target' => 'pedestrian',
                        ],
                    ],
                ],
            ],
            'yellow'     => [],
            'red'        => [],
            'pedestrian' => [],
        ],
    ]);

    $transitions = $machine->stateDefinitions['green']->transitionDefinitions;
    expect($transitions)
        ->toBeArray()
        ->toHaveCount(1)
        ->toHaveKey('TIMER');

    /** @var TransitionDefinition $guardedTimerTransitions */
    $guardedTimerTransitions = $transitions['TIMER'];

    expect($guardedTimerTransitions)->toBeInstanceOf(TransitionDefinition::class);

    expect($guardedTimerTransitions->branches[0]->target->key)->toBe('yellow');
    expect($guardedTimerTransitions->branches[0]->guards)->toBe(['guard1']);

    expect($guardedTimerTransitions->branches[1]->target->key)->toBe('red');
    expect($guardedTimerTransitions->branches[1]->guards)->toBe(['guard2']);

    expect($guardedTimerTransitions->branches[2]->target->key)->toBe('pedestrian');
    expect($guardedTimerTransitions->branches[2]->guards)->toBeNull();
});

// === Always Transition Tests ===

test('always transitions', function (): void {
    $machine = MachineDefinition::define(config: [
        'initial' => 'stateA',
        'states'  => [
            'stateA' => [
                'on' => [
                    'EVENT' => 'stateB',
                ],
            ],
            'stateB' => [
                'on' => [
                    '@always' => 'stateC',
                ],
            ],
            'stateC' => [],
        ],
    ]);

    $newState = $machine->transition(
        event: ['type' => 'EVENT'],
    );

    expect($newState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'stateC']);
});

test('always transitions with initial jump', function (): void {
    $machine = MachineDefinition::define(config: [
        'initial' => 'stateB',
        'states'  => [
            'stateB' => [
                'on' => [
                    '@always' => 'stateC',
                ],
            ],
            'stateC' => [],
        ],
    ]);

    /** @var \Tarfinlabs\EventMachine\Actor\State $newState */
    $newState = $machine->getInitialState();

    expect($newState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'stateC']);
});

test('always transitions with initial machine jump', function (): void {
    $machine = AbcMachine::create();

    expect($machine->state->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'stateC']);
});

test('always guarded transitions', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'initial' => 'stateA',
            'context' => [
                'count' => 1,
            ],
            'states' => [
                'stateA' => [
                    'on' => [
                        'EVENT' => 'stateB',
                        'INC'   => [
                            'actions' => 'incrementAction',
                        ],
                    ],
                ],
                'stateB' => [
                    'on' => [
                        '@always' => [
                            [
                                'target' => 'stateC',
                                'guards' => 'isEvenGuard',
                            ],
                            [
                                'target' => 'stateD',
                            ],
                        ],
                    ],
                ],
                'stateC' => [
                    'on' => [
                        'EVENT_A' => 'stateA',
                    ],
                ],
                'stateD' => [
                    'on' => [
                        'EVENT_A' => 'stateA',
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'incrementAction' => function (ContextManager $context): void {
                    $context->set('count', $context->get('count') + 1);
                },
            ],
            'guards' => [
                'isEvenGuard' => function (ContextManager $context): bool {
                    return $context->get('count') % 2 === 0;
                },
            ],
        ],
    );

    expect(
        $machine->stateDefinitions['stateB']
            ->transitionDefinitions[TransitionProperty::Always->value]
            ->isGuarded
    )
        ->toBeTrue();

    $newState = $machine->transition(
        event: ['type' => 'EVENT']
    );

    expect($newState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'stateD']);
});
