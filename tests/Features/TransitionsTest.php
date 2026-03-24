<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Locks\MachineLockManager;
use Tarfinlabs\EventMachine\Tests\Stubs\Models\ModelA;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\AsdMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Events\EEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Events\SEvent;
use Tarfinlabs\EventMachine\Exceptions\MachineAlreadyRunningException;

// === Basic Machine Transition Tests ===

it('can transition through a sequence of states using events', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'initial' => 'green',
            'states'  => [
                'green' => [
                    'on' => [
                        'NEXT' => 'yellow',
                    ],
                ],
                'yellow' => [
                    'on' => [
                        'NEXT' => 'red',
                    ],
                ],
                'red' => [],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    $greenState = $machine->getInitialState();
    expect($greenState)
        ->toBeInstanceOf(State::class)
        ->and($greenState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'green']);

    $yellowState = $machine->transition(event: ['type' => 'NEXT']);
    expect($yellowState)
        ->toBeInstanceOf(State::class)
        ->and($yellowState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'yellow']);

    $redState = $machine->transition(event: ['type' => 'NEXT'], state: $yellowState);
    expect($redState)
        ->toBeInstanceOf(State::class)
        ->and($redState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'red']);
});

it('should apply the given state\'s context data to the machine\'s context when transitioning', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'initial' => 'active',
            'context' => [
                'count'  => 0,
                'status' => 'abc',
            ],
            'states' => [
                'active' => [
                    'on' => [
                        'INCREASE' => ['actions' => 'incrementAction'],
                    ],
                ],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
            'actions' => [
                'incrementAction' => function (ContextManager $context): void {
                    $context->set('count', $context->get('count') + 1);
                },
            ],
        ],
    );

    $initialState = $machine->getInitialState();
    $initialState->context->set('count', 5);

    $newState = $machine->transition(event: [
        'type' => 'INCREASE',
    ], state: $initialState);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'active']);
    expect($newState->context->count)->toBe(6);
    expect($newState->context->get('status'))->toBe('abc');
});

// === Transactional Tests ===

it('If the event is not transactional, its data is persistent', function (): void {
    $machine = AsdMachine::create();

    expect(fn () => $machine->send(new SEvent(isTransactional: false)))
        ->toThrow(new Exception('error'));

    $models = ModelA::all();

    expect($models)
        ->toHaveCount(1)
        ->first()->value->toBe('new value');
});

it('If the event is transactional, it rolls back the data', function (): void {
    $machine = AsdMachine::create();

    expect(fn () => $machine->send(new SEvent()))
        ->toThrow(new Exception('error'));

    $models = ModelA::all();

    expect($models)
        ->toHaveCount(0);
});

// === Machine Running State Tests ===

it('If the machine is already running, it will throw exception', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = AsdMachine::create();
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;

    // Hold a lock so send() cannot acquire it
    $handle = MachineLockManager::acquire($rootEventId);

    try {
        $machine->send(new EEvent());
    } finally {
        $handle->release();
        config()->set('machine.parallel_dispatch.enabled', false);
    }
})->throws(MachineAlreadyRunningException::class);

// === Forbidden Transition Tests ===

test('Top-level event transition can switch from a deeply nested state to another top-level state', function (): void {
    $machine = Machine::create([
        'config' => [
            'id'      => 'm',
            'initial' => 'a.b.c.d',
            'states'  => [
                'a' => [
                    'on' => [
                        '@event' => 'x',
                    ],
                    'states' => [
                        'b' => [
                            'states' => [
                                'c' => [
                                    'states' => [
                                        'd' => [],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'x' => [],
            ],
        ],
        'behavior' => [
            'context' => GenericContext::class,
        ],
    ]);

    $machine->start();

    expect($machine->state->value)->toBe(['m.a.b.c.d']);

    $machine->send(['type' => '@event']);

    expect($machine->state->value)->toBe(['m.x']);

    expect($machine->state->history->pluck('type')->toArray())
        ->toBe([
            'm.start',
            'm.state.a.b.c.d.enter',
            'm.state.a.b.c.d.entry.start',
            'm.state.a.b.c.d.entry.finish',
            '@event',
            'm.transition.a.b.c.d.@event.start',
            'm.transition.a.b.c.d.@event.finish',
            'm.state.a.b.c.d.exit.start',
            'm.state.a.b.c.d.exit.finish',
            'm.state.a.b.c.d.exit',
            'm.state.x.enter',
            'm.state.x.entry.start',
            'm.state.x.entry.finish',
        ]);
});

test('Forbidded Transition: Nested state can override top-level event transition defined in parent state', function (): void {
    $machine = Machine::create([
        'config' => [
            'id'      => 'm',
            'initial' => 'a.b.c.d',
            'states'  => [
                'a' => [
                    'on' => [
                        '@event' => 'x',
                    ],
                    'states' => [
                        'b' => [
                            'states' => [
                                'c' => [
                                    'states' => [
                                        'd' => [
                                            'on' => [
                                                '@event' => null,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'x' => [],
            ],
        ],
        'behavior' => [
            'context' => GenericContext::class,
        ],
    ]);

    $machine->start();

    expect($machine->state->value)->toBe(['m.a.b.c.d']);

    $machine->send(['type' => '@event']);

    expect($machine->state->value)->toBe(['m.a.b.c.d']);
});

test('Top-Level Transitions', function (): void {
    $machine = Machine::create([
        'config' => [
            'context' => [
                'value' => 0,
            ],
            'on' => [
                '@event' => [
                    'actions' => 'increaseValueAction',
                ],
            ],
        ],
        'behavior' => [
            'context' => GenericContext::class,
            'actions' => [
                'increaseValueAction' => function (ContextManager $context): void {
                    $context->value++;
                },
            ],
        ],
    ]);

    $machine->send(event: '@event');

    expect($machine->state->context->value)->toBe(1);
});
