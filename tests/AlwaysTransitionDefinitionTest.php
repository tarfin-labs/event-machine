<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Enums\TransitionProperty;
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
