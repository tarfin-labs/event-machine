<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

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

    $newState = $machine->transition(state: null, event: ['type' => 'MUT']);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe(['(machine).active'])
        ->and($newState->context->data)->toBe(['count' => 1]);

    $newState = $machine->transition(state: $newState, event: ['type' => 'INC']);
    expect($newState->context->data)->toBe(['count' => 2]);

    $newState = $machine->transition(state: $newState, event: [
        'type' => 'MUT',
    ]);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe(['(machine).active'])
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

    $newState = $machine->transition(state: null, event: ['type' => 'MUT']);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe(['(machine).active'])
        ->and($newState->context->data)->toBe(['count' => 1]);

    $newState = $machine->transition(state: $newState, event: ['type' => 'INC']);
    expect($newState->context->data)->toBe(['count' => 2]);

    $newState = $machine->transition(state: $newState, event: ['type' => 'DEC']);
    expect($newState->context->data)->toBe(['count' => 1]);

    $newState = $machine->transition(state: $newState, event: [
        'type' => 'MUT',
    ]);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe(['(machine).active']);
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
                                'target' => 'yellow',
                                'guards' => 'isOneGuard',
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

    $newState = $machine->transition(state: null, event: ['type' => 'TIMER']);
    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe(['(machine).yellow']);

    $newState = $machine->transition(
        state: new State(
            context: new ContextManager(['value' => 2]),
            activeStateDefinition: $machine->stateDefinitions['green'],
        ),
        event: ['type' => 'TIMER']
    );

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe(['(machine).red']);

    $newState = $machine->transition(
        state: new State(
            context: new ContextManager(['value' => 3]),
            activeStateDefinition: $machine->stateDefinitions['green'],
        ),
        event: ['type' => 'TIMER']
    );

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe(['(machine).pedestrian']);
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

    $newState = $machine->transition(state: null, event: ['type' => 'EVENT']);

    expect($newState->value)->toBe(['(machine).yellow']);
});
