<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\EventDefinition;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

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
                        'ADD' => ['actions' => 'additionAction'],
                        'SUB' => ['actions' => 'subtractionAction'],
                        'INC' => ['actions' => 'incrementAction'],
                        'DEC' => ['actions' => 'decrementAction'],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'additionAction' => function (ContextManager $context, EventDefinition $eventDefinition): void {
                    $context->set('count', $context->get('count') + $eventDefinition->payload['value']);
                },
                'subtractionAction' => function (ContextManager $context, EventDefinition $eventDefinition): void {
                    $context->set('count', $context->get('count') - $eventDefinition->payload['value']);
                },
                'incrementAction' => function (ContextManager $context): void {
                    $context->set('count', $context->get('count') + 1);
                },
                'decrementAction' => function (ContextManager $context): void {
                    $context->set('count', $context->get('count') - 1);
                },
            ],
        ],
    );

    $addState = $machine->transition(state: null, event: [
        'type'    => 'ADD',
        'payload' => [
            'value' => 37,
        ],
    ]);

    expect($addState)
        ->toBeInstanceOf(State::class)
        ->and($addState->value)->toBe(['(machine).active']);

    $subState = $machine->transition(state: $addState, event: [
        'type'    => 'SUB',
        'payload' => [
            'value' => 17,
        ],
    ]);

    expect($subState)
        ->toBeInstanceOf(State::class)
        ->and($subState->value)->toBe(['(machine).active'])
        ->and($subState->context->get('count'))->toBe(20);

    $incState = $machine->transition(state: $subState, event: [
        'type' => 'INC',
    ]);

    expect($incState)
        ->toBeInstanceOf(State::class)
        ->and($incState->value)->toBe(['(machine).active'])
        ->and($incState->context->get('count'))->toBe(21);

    $decState = $machine->transition(state: $incState, event: [
        'type' => 'DEC',
    ]);

    expect($decState)
        ->toBeInstanceOf(State::class)
        ->and($decState->value)->toBe(['(machine).active'])
        ->and($decState->context->get('count'))->toBe(20);
});
