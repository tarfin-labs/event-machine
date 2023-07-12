<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\EventDefinition;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

it('stores external events', function (): void {
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

    $newState = $machine->transition(event: [
        'type' => 'GREEN_TIMER',
    ]);

    $newState = $machine->transition(event: [
        'type' => 'RED_TIMER',
    ], state: $newState);

    expect($newState->history)->toHaveCount(3);
});

it('stores internal action events', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'initial' => 'active',
            'context' => [
                'count' => 0,
            ],
            'states' => [
                'active' => [
                    'on' => [
                        'ADD' => [
                            'actions' => 'additionAction',
                        ],
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

    $newState = $machine->transition(event: [
        'type'    => 'ADD',
        'payload' => [
            'value' => 37,
        ],
    ]);

    expect($newState->history->pluck('type')->toArray())
        ->toHaveCount(4)
        ->toEqual([
            'machine.init',
            'ADD',
            'machine.action.additionAction.init',
            'machine.action.additionAction.done',
        ]);
});

it('stores internal guard events', function (): void {
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

    $newState = $machine->transition(event: ['type' => 'MUT']);

    expect($newState->history->pluck('type')->toArray())
        ->toHaveCount(4)
        ->toEqual([
            'machine.init',
            'MUT',
            'machine.guard.isEvenGuard.init',
            'machine.guard.isEvenGuard.fail',
        ]);
});
