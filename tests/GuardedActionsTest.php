<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\MachineValidationException;
use Tarfinlabs\EventMachine\Tests\Stubs\Guards\IsValidatedOddGuard;
use Tarfinlabs\EventMachine\Exceptions\InvalidGuardedTransitionException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

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

it('throws an exception when a validation guard is used inside a guarded transition', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'initial' => 'green',
            'context' => [
                'counts' => [
                    'oddCount' => 0,
                ],
            ],
            'states' => [
                'green' => [
                    'on' => [
                        'TIMER' => [
                            [
                                'target' => 'yellow',
                                'guards' => IsValidatedOddGuard::class,
                            ],
                            [
                                'target' => 'pedestrian',
                            ],
                        ],
                    ],
                ],
                'yellow'     => [],
                'pedestrian' => [],
            ],
        ],
    );
})->throws(
    exception: InvalidGuardedTransitionException::class,
    exceptionMessage: "Validation Guard Behavior is not allowed inside guarded transitions. Error occurred during event 'TIMER' in state definition 'machine.green'."
);
