<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\ResultBehavior;
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

    $addState = $machine->transition(event: [
        'type'    => 'ADD',
        'payload' => [
            'value' => 37,
        ],
    ]);

    expect($addState)
        ->toBeInstanceOf(State::class)
        ->and($addState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'active']);

    $subState = $machine->transition(event: [
        'type'    => 'SUB',
        'payload' => [
            'value' => 17,
        ],
    ], state: $addState);

    expect($subState)
        ->toBeInstanceOf(State::class)
        ->and($subState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'active'])
        ->and($subState->context->get('count'))->toBe(20);

    $incState = $machine->transition(event: [
        'type' => 'INC',
    ], state: $subState);

    expect($incState)
        ->toBeInstanceOf(State::class)
        ->and($incState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'active'])
        ->and($incState->context->get('count'))->toBe(21);

    $decState = $machine->transition(event: [
        'type' => 'DEC',
    ], state: $incState);

    expect($decState)
        ->toBeInstanceOf(State::class)
        ->and($decState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'active'])
        ->and($decState->context->get('count'))->toBe(20);
});

test('result actions can return', function (): void {
    // 1. Arrange
    $value = random_int(10, 20);

    $multipleWithItselfAction = new class extends ResultBehavior {
        public function __invoke(
            ContextManager $context,
            EventBehavior $eventBehavior,
            ?array $arguments = null
        ): int {
            return $eventBehavior->payload['value'] * $eventBehavior->payload['value'];
        }
    };

    // 2. Act
    $result = $multipleWithItselfAction(
        context: new ContextManager(),
        eventBehavior: EventDefinition::from([
            'type'    => 'ADD',
            'payload' => [
                'value' => $value,
            ],
        ]),
    );

    // 3. Assert
    expect($result)->toBe($value * $value);
});
