<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

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

    $newState = $machine->transition(state: null, event: [
        'type' => 'ACTIVATE',
    ]);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe(['active']);
    expect($newState->contextData)->toBe(['count' => 1]);

    // Ensure that the machine's context has been changed.
    expect($machine->context->get('count'))->toBe(1);
});
