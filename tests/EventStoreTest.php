<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
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

    expect($newState->history->pluck('type')->toArray())
        ->toEqual([
            'traffic_light.start',
            'traffic_light.state.green.enter',
            'traffic_light.state.green.entry.start',
            'traffic_light.state.green.entry.finish',
            'GREEN_TIMER',
            'traffic_light.transition.green.GREEN_TIMER.start',
            'traffic_light.transition.green.GREEN_TIMER.finish',
            'traffic_light.state.green.exit.start',
            'traffic_light.state.green.exit.finish',
            'traffic_light.state.green.exit',
            'traffic_light.state.yellow.enter',
            'traffic_light.state.yellow.entry.start',
            'traffic_light.state.yellow.entry.finish',
            'RED_TIMER',
            'traffic_light.transition.yellow.RED_TIMER.start',
            'traffic_light.transition.yellow.RED_TIMER.finish',
            'traffic_light.state.yellow.exit.start',
            'traffic_light.state.yellow.exit.finish',
            'traffic_light.state.yellow.exit',
            'traffic_light.state.red.enter',
            'traffic_light.state.red.entry.start',
            'traffic_light.state.red.entry.finish',
        ]);
});

it('stores internal action events', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'm',
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
                'additionAction' => function (ContextManager $context, EventBehavior $eventBehavior): void {
                    $context->set('count', $context->get('count') + $eventBehavior->payload['value']);
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
        ->toEqual([
            'm.start',
            'm.state.active.enter',
            'm.state.active.entry.start',
            'm.state.active.entry.finish',
            'ADD',
            'm.transition.active.ADD.start',
            'm.action.additionAction.start',
            'm.action.additionAction.finish',
            'm.transition.active.ADD.finish',
            'm.state.active.exit.start',
            'm.state.active.exit.finish',
            'm.state.active.exit',
            'm.state.active.enter',
        ]);
});

it('stores internal guard events', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'in',
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
        ->toEqual([
            'in.start',
            'in.state.active.enter',
            'in.state.active.entry.start',
            'in.state.active.entry.finish',
            'MUT',
            'in.transition.active.MUT.start',
            'in.guard.isEvenGuard.start',
            'in.guard.isEvenGuard.fail',
            'in.transition.active.MUT.fail',
        ]);
});
