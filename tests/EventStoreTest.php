<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\EventDefinition;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

it('stores events', function (): void {
    $machine = MachineDefinition::define(config: [
        'id'      => 'traffic_light',
        'initial' => 'green',
        'states'  => [
            'green' => [
                'on' => [
                    'GREEN_TIMER' => 'yellow',
                ],
            ],
            'yellow' => [
                'on' => [
                    'RED_TIMER' => 'red',
                ],
            ],
            'red' => [],
        ],
    ]);

    $newState = $machine->transition(state: null, event: [
        'type' => 'GREEN_TIMER',
    ]);

    $newState = $machine->transition(state: $newState, event: [
        'type' => 'RED_TIMER',
    ]);

    expect($newState->history)->toHaveCount(3);
});

it('stores action events', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'initial' => 'active',
            'context' => [
                'count' => 0,
            ],
            'states' => [
                'active' => [
                    'on' => [
                        'ADD' => ['actions' => 'additionAction'],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'additionAction' => function (ContextManager $context, EventDefinition $eventDefinition): void {
                    $context->set('count', $context->get('count') + $eventDefinition->payload['value']);
                },
            ],
        ]
    );

    $newState = $machine->transition(state: null, event: [
        'type'    => 'ADD',
        'payload' => [
            'value' => 37,
        ],
    ]);

    expect($newState->history->pluck('type')->toArray())
        ->toHaveCount(4)
        ->toEqual(['machine.initial', 'ADD', 'action.additionAction.initial', 'action.additionAction.done']);
});

it('stores guard events', function (): void {
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
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'multiplyByTwoAction' => function (ContextManager $context): void {
                    $context->set('count', $context->get('count') * 2);
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

    expect($newState->history->pluck('type')->toArray())
        ->toHaveCount(4)
        ->toEqual(['machine.initial', 'MUT', 'guard.isEvenGuard.initial', 'guard.isEvenGuard.fail']);
});
