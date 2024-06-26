<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Illuminate\Validation\ValidationException;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\ValidatedEvent;

test('an event payload can be validated', function (): void {
    $randomMachineValue = random_int(1, 10);

    $machine = MachineDefinition::define(
        config: [
            'initial' => 'stateA',
            'context' => [
                'value' => $randomMachineValue,
            ],
            'states' => [
                'stateA' => [
                    'on' => [
                        ValidatedEvent::class => [
                            'target'  => 'stateB',
                            'actions' => 'updateContext',
                        ],
                    ],
                ],
                'stateB' => [],
            ],
        ],
        behavior: [
            'actions' => [
                'updateContext' => function (ContextManager $context, ValidatedEvent $event) {
                    return $context->set('value', $context->get('value') + $event->payload['attribute']);
                },
            ],
        ]
    );

    $randomEventValue = random_int(1, 10);

    $newState = $machine->transition(event: [
        'type'    => 'VALIDATED_EVENT',
        'payload' => [
            'attribute' => $randomEventValue,
        ],
    ]);

    expect($newState->context->data['value'])->toBe($randomMachineValue + $randomEventValue);
});

test('an event validator can stopping on the first validation failure', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'initial' => 'stateA',
            'context' => [
                'value' => 'test',
            ],
            'states' => [
                'stateA' => [
                    'on' => [
                        ValidatedEvent::class => [
                            'target'  => 'stateB',
                            'actions' => 'updateContext',
                        ],
                    ],
                ],
                'stateB' => [],
            ],
        ],
        behavior: [
            'actions' => [
                'foo' => function (ContextManager $context, ValidatedEvent $event) {
                    return 'bar';
                },
            ],
        ]
    );

    expect(function () use ($machine): void {
        $machine->transition(event: [
            'type'    => 'VALIDATED_EVENT',
            'payload' => [],
        ]);
    })->toThrow(
        exception: ValidationException::class,
        exceptionMessage: 'Custom validation message for the attribute.'
    );

});
