<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\State;
use Tarfinlabs\EventMachine\ContextDefinition;
use Tarfinlabs\EventMachine\MachineDefinition;

it('can update context using actions defined in transition definitions', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'initial' => 'active',
            'context' => [
                'count' => 0,
            ],
            'states' => [
                'active' => [
                    'on' => [
                        'INC' => [
                            'actions' => 'incrementAction',
                        ],
                        'DEC' => [
                            'actions' => 'decrementAction',
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'incrementAction' => function (ContextDefinition $context, array $event): void {
                    $context->set('count', $context->get('count') + $event['value']);
                },
                'decrementAction' => function (ContextDefinition $context, array $event): void {
                    $context->set('count', $context->get('count') - $event['value']);
                },
            ],
        ],
    );

    $state = $machine->transition(state: null, event: [
        'type'  => 'INC',
        'value' => 37,
    ]);

    expect($state)->toBeInstanceOf(State::class);
    expect($state->value)->toBe(['active']);
    expect($machine->context->get('count'))->toBe(37);
});
