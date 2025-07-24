<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\MachineValidationException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

// === Entry Actions Tests ===

it('should run entry actions when transitioning to a new state', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'initial' => 'inactive',
            'context' => [
                'count' => 0,
            ],
            'states' => [
                'inactive' => [
                    'on' => [
                        'ACTIVATE' => 'active',
                    ],
                ],
                'active' => [
                    'entry' => 'incrementAction',
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'incrementAction' => function (ContextManager $context): void {
                    $context->set('count', $context->get('count') + 1);
                },
            ],
        ],
    );

    $newState = $machine->transition(event: [
        'type' => 'ACTIVATE',
    ]);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'active'])
        ->and($newState->context->data)->toBe(['count' => 1]);
});

it('should run entry actions when transitioning to a substate', function (): void {
    $machine = Machine::withDefinition(MachineDefinition::define(
        config: [
            'initial' => 'inactive',
            'context' => [
                'count' => 0,
            ],
            'states' => [
                'inactive' => [
                    'on' => [
                        'ACTIVATE' => 'active',
                    ],
                ],
                'active' => [
                    'initial' => 'idle',
                    'states'  => [
                        'idle' => [
                            'entry' => 'incrementAction',
                        ],
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
        ],
    ));

    $newState = $machine->send(event: [
        'type' => 'ACTIVATE',
    ]);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'active.idle'])
        ->and($newState->context->data)->toBe(['count' => 1]);

    $this->assertDatabaseHas(MachineEvent::class, [
        'machine_value' => json_encode([$newState->currentStateDefinition->id], JSON_THROW_ON_ERROR),
    ]);
});

// === Exit Actions Tests ===

it('should run exit actions when transitioning from a state', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'initial' => 'inactive',
            'context' => [
                'count' => 0,
            ],
            'states' => [
                'inactive' => [
                    'exit' => 'incrementAction',
                    'on'   => ['ACTIVATE' => 'active'],
                ],
                'active' => [],
            ],
        ],
        behavior: [
            'actions' => [
                'incrementAction' => function (ContextManager $context): void {
                    $context->set('count', $context->get('count') + 1);
                },
            ],
        ],
    );

    $newState = $machine->transition(event: [
        'type' => 'ACTIVATE',
    ]);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'active'])
        ->and($newState->context->data)->toBe(['count' => 1]);
});

// === Guarded Actions Tests ===

it('should run the guarded action when the guards are passed', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'initial' => 'active',
            'context' => [
                'count' => 1,
            ],
            'states' => [
                'active' => [
                    'on' => [
                        'MUT' => [
                            'guards'  => 'isEvenGuard',
                            'actions' => 'multiplyByTwoAction',
                        ],
                        'INC' => ['actions' => 'incrementAction'],
                        'DEC' => ['actions' => 'decrementAction'],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'multiplyByTwoAction' => function (ContextManager $context): void {
                    $context->set('count', $context->get('count') * 2);
                },
                'incrementAction' => function (ContextManager $context): void {
                    $context->set('count', $context->get('count') + 1);
                },
                'decrementAction' => function (ContextManager $context): void {
                    $context->set('count', $context->get('count') - 1);
                },
            ],
            'guards' => [
                'isEvenGuard' => function (ContextManager $context): bool {
                    return $context->get('count') % 2 === 0;
                },
            ],
        ],
    );

    $newState = $machine->transition(event: ['type' => 'MUT']);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'active'])
        ->and($newState->context->data)->toBe(['count' => 1]);

    $newState = $machine->transition(event: ['type' => 'INC'], state: $newState);
    expect($newState->context->data)->toBe(['count' => 2]);

    $newState = $machine->transition(event: [
        'type' => 'MUT',
    ], state: $newState);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'active'])
        ->and($newState->context->data)->toBe(['count' => 4]);
});

it('should not run the guarded action when the guards are not passed', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'initial' => 'active',
            'context' => [
                'count' => 1,
            ],
            'states' => [
                'active' => [
                    'on' => [
                        'MUT' => [
                            'guards'  => 'isEvenGuard',
                            'actions' => 'multiplyByTwoAction',
                        ],
                        'INC' => ['actions' => 'incrementAction'],
                        'DEC' => ['actions' => 'decrementAction'],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'multiplyByTwoAction' => function (ContextManager $context): void {
                    $context->set('count', $context->get('count') * 2);
                },
                'incrementAction' => function (ContextManager $context): void {
                    $context->set('count', $context->get('count') + 1);
                },
                'decrementAction' => function (ContextManager $context): void {
                    $context->set('count', $context->get('count') - 1);
                },
            ],
            'guards' => [
                'isEvenGuard' => function (ContextManager $context): bool {
                    return $context->get('count') % 2 === 0;
                },
            ],
        ],
    );

    $newState = $machine->transition(event: ['type' => 'MUT']);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'active'])
        ->and($newState->context->data)->toBe(['count' => 1]);

    $newState = $machine->transition(event: ['type' => 'INC'], state: $newState);
    expect($newState->context->data)->toBe(['count' => 2]);

    $newState = $machine->transition(event: ['type' => 'DEC'], state: $newState);
    expect($newState->context->data)->toBe(['count' => 1]);

    $newState = $machine->transition(event: [
        'type' => 'MUT',
    ], state: $newState);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'active']);
    // Guards is not passed, the action will not be executed
    expect($newState->context->data)->toBe(['count' => 1]);
});

it('should transition through multiple if-else targets based on guards', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'initial' => 'green',
            'context' => [
                'value' => 1,
            ],
            'states' => [
                'green' => [
                    'on' => [
                        'TIMER' => [
                            [
                                'target'      => 'yellow',
                                'guards'      => 'isOneGuard',
                                'description' => 'sample description',
                            ],
                            [
                                'target' => 'red',
                                'guards' => 'isTwoGuard',
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
        ],
        behavior: [
            'guards' => [
                'isOneGuard' => function (ContextManager $context): bool {
                    return $context->get('value') === 1;
                },
                'isTwoGuard' => function (ContextManager $context): bool {
                    return $context->get('value') === 2;
                },
            ],
        ],
    );

    $newState = $machine->transition(event: ['type' => 'TIMER']);
    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'yellow']);

    $newState = $machine->transition(
        event: ['type' => 'TIMER'],
        state: new State(
            context: new ContextManager(['value' => 2]),
            currentStateDefinition: $machine->stateDefinitions['green'],
        )
    );

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'red']);

    $newState = $machine->transition(
        event: ['type' => 'TIMER'],
        state: new State(
            context: new ContextManager(['value' => 3]),
            currentStateDefinition: $machine->stateDefinitions['green'],
        )
    );

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'pedestrian']);

    expect($machine->stateDefinitions['green']->transitionDefinitions['TIMER']->branches[0]->description)
        ->toBe('sample description');
});

it('should prevent infinite loops when no guards evaluate to true for @always transitions', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'initial' => 'green',
            'states'  => [
                'green' => [
                    'on' => [
                        'EVENT' => 'yellow',
                    ],
                ],
                'yellow' => [
                    'on' => [
                        '@always' => [
                            'target' => 'red',
                            'guards' => 'guard1',
                        ],
                    ],
                ],
                'red' => [],
            ],
        ],
        behavior: [
            'guards' => [
                'guard1' => fn (): bool => false,
            ],
        ],
    );

    $newState = $machine->transition(event: ['type' => 'EVENT']);

    expect($newState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'yellow']);
});

it('can throw MachineValidationException and persist history', function (): void {
    $machine = TrafficLightsMachine::create();

    $machine->send(event: ['type' => 'INC']);

    expect(fn () => $machine->send(event: ['type' => 'MUT']))
        ->toThrow(MachineValidationException::class, 'Count is not even');

    $this->assertDatabaseHas(MachineEvent::class, [
        'id' => $machine->state->history->last()->id,
    ]);
});
