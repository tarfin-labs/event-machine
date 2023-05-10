<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AbcMachine;

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
        state: null,
        event: ['type' => 'EVENT']
    );

    expect($newState->value)->toBe(['stateC']);
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

    $newState = $machine->getInitialState();

    expect($newState->value)->toBe(['stateC']);
});

test('always transitions with initial machine jump', function (): void {
    $machineActor = AbcMachine::start();

    expect($machineActor->state->value)->toBe(['stateC']);
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
                                'target' => 'stateC',
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

    $newState = $machine->transition(
        state: null,
        event: ['type' => 'EVENT']
    );

    expect($newState->value)->toBe(['stateC']);
});
