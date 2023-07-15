<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\EventDefinition;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

test('an action can have multiple arguments', function (): void {
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
                            'actions' => 'additionAction:5,4,3,2,1',
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'additionAction' => function (ContextManager $context, EventDefinition $eventDefinition, array $arguments = null): void {
                    $context->count += array_sum($arguments);
                },
            ],
        ],
    );

    $state = $machine->transition(event: ['type' => 'ADD']);

    expect($state->context->count)->toBe(15);
});

test('a guard can have multiple arguments', function (): void {
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
                            'guards'  => 'biggerThan:5',
                            'actions' => 'additionAction:10',
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'additionAction' => function (ContextManager $context, EventDefinition $eventDefinition, array $arguments = null): void {
                    $context->count += array_sum($arguments);
                },
            ],
            'guards' => [
                'biggerThan' => function (ContextManager $context, EventDefinition $eventDefinition, array $arguments = null): bool {
                    return $context->count > $arguments[0];
                },
            ],
        ],
    );

    $state = $machine->transition(event: ['type' => 'ADD']);

    expect($state->context->count)->toBe(0);
});
