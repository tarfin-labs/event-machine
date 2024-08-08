<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Tests\Stubs\Models\ModelA;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\AsdMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Events\EEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Events\SEvent;
use Tarfinlabs\EventMachine\Exceptions\MachineAlreadyRunningException;

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
                'count'     => 0,
                'someValue' => 'abc',
            ],
            'states' => [
                'active' => [
                    'on' => [
                        'INC' => ['actions' => 'incrementAction'],
                    ],
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

    $initialState = $machine->getInitialState();
    $initialState->context->set('count', 5);

    $newState = $machine->transition(event: [
        'type' => 'INC',
    ], state: $initialState);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'active']);
    expect($newState->context->data)->toBe(['count' => 6, 'someValue' => 'abc']);
});

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

    expect(fn () => $machine->send(new SEvent))
        ->toThrow(new Exception('error'));

    $models = ModelA::all();

    expect($models)
        ->toHaveCount(0);
});

it('If the machine is already running, it will throw exception', function (): void {
    $machine = AsdMachine::create();
    $machine->persist();

    $rootEventId = 'mre:'.$machine->state->history->first()->root_event_id;

    Cache::shouldReceive('lock')
        ->once()
        ->with($rootEventId, 60)
        ->andReturnSelf();

    Cache::shouldReceive('get')
        ->once()
        ->withAnyArgs()
        ->andReturn(false);

    $machine->send(new EEvent);
})->throws(MachineAlreadyRunningException::class);
