<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\IncreaseEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\AddAnotherValueEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachineCompact;

test('TrafficLightsMachine definition returns a MachineDefinition instance', function (): void {
    $machine = TrafficLightsMachine::build();

    expect($machine)->toBeInstanceOf(MachineDefinition::class);
});

test('TrafficLightsMachine transitions between states using EventMachine', function (): void {
    $machine = TrafficLightsMachine::build();

    $newState = $machine->transition(state: null, event: ['type' => 'MUT']);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe(['active']);
    expect($newState->context)->toBe([
        'count' => 1,
        'data'  => [],
    ]);

    /** @var \Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext $context */
    $context = $machine->context;

    // Ensure that the machine's context has not been changed.
    expect($context->count)->toBe(1);

    $newState = $machine->transition(state: $newState, event: ['type' => 'INC']);
    expect($newState->context['count'])->toBe(2);

    $newState = $machine->transition(state: $newState, event: [
        'type' => 'MUT',
    ]);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe(['active']);
    expect($newState->context['count'])->toBe(4);

    // Ensure that the machine's context has been changed.
    expect($context->count)->toBe(4);

    $newState = $machine->transition(state: $newState, event: [
        'type'    => 'ADD',
        'payload' => [
            'value' => 16,
        ],
    ]);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe(['active']);
    expect($newState->context['count'])->toBe(20);

    // Ensure that the machine's context has been changed.
    expect($context->count)->toBe(20);
});

test('TrafficLightsMachine transitions between states using an IncreaseEvent implementing EventBehavior', function (): void {
    $machine = TrafficLightsMachine::build();

    $increaseEvent = new IncreaseEvent();
    expect($increaseEvent)->toBeInstanceOf(EventBehavior::class);

    $newState = $machine->transition(state: null, event: $increaseEvent);
    expect($newState->context['count'])->toBe(2);
});

test('TrafficLightsMachine transitions between states using an AddAnotherValueEvent implementing EventBehavior', function (): void {
    $machine = TrafficLightsMachine::build();

    $addAnotherValueEvent = new AddAnotherValueEvent(41);
    expect($addAnotherValueEvent)->toBeInstanceOf(EventBehavior::class);

    $newState = $machine->transition(state: null, event: $addAnotherValueEvent);
    expect($newState->context['count'])->toBe(42);
});

test('TrafficLightsMachineCompact can be build', function (): void {
    $machine = TrafficLightsMachineCompact::build();

    expect($machine)->toBeInstanceOf(MachineDefinition::class);
});
