<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

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

    $newState = $machine->transition(state: null, event: [
        'type' => 'ACTIVATE',
    ]);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe(['active']);
    expect($newState->context['data'])->toBe(['count' => 1]);

    // Ensure that the machine's context has been changed.
    expect($machine->context->get('count'))->toBe(1);
});
