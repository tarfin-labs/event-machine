<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextDefinition;
use Tarfinlabs\EventMachine\MachineDefinition;

it('can assign from an event', function (): void {
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
                'decrementAction' => function (ContextDefinition $context, array $event): array {
                    $context->set('count', $context->get('count') - $event['value']);
                },
            ],
        ],
    );

    $machine->transition(state: null, event: [
        'type'  => 'INC',
        'value' => 37,
    ]);

    expect($machine->context->get('count'))->toBe(37);
});
