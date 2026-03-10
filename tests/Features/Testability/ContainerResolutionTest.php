<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\CounterService;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\TestabilityMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\Actions\IncrementWithServiceAction;

// ─── Behavior with no constructor DI still works ─────────────

it('resolves behaviors without constructor DI through the container', function (): void {
    $machine = TrafficLightsMachine::create();
    $machine->send(['type' => 'INCREASE']);

    expect($machine->state->context->get('count'))->toBe(1);
});

// ─── Behavior with constructor DI gets service injected ──────

it('resolves behaviors with constructor DI and injects the service', function (): void {
    $machine = TestabilityMachine::create();
    $machine->send(['type' => 'INCREMENT']);

    expect($machine->state->context->get('count'))->toBe(1);
});

it('allows mocking constructor DI service for behavior testing', function (): void {
    $mock = Mockery::mock(CounterService::class);
    $mock->shouldReceive('increment')->with(0)->once()->andReturn(42);
    App::instance(CounterService::class, $mock);

    $machine = TestabilityMachine::create();
    $machine->send(['type' => 'INCREMENT']);

    expect($machine->state->context->get('count'))->toBe(42);
});

// ─── EventQueue is still passed correctly ────────────────────

it('passes eventQueue to behaviors resolved through container', function (): void {
    $machine = TestabilityMachine::create();
    $machine->send(['type' => 'INCREMENT']);

    // If eventQueue wasn't passed, the behavior constructor would fail
    // The fact that the machine runs without error proves it works
    expect($machine->state->context->get('count'))->toBe(1);
});

// ─── Multiple behaviors in same machine resolve through container ──

it('resolves multiple behaviors through container in the same machine', function (): void {
    $machine = TestabilityMachine::create();
    $machine->send(['type' => 'INCREMENT']);
    $machine->send(['type' => 'INCREMENT']);
    $machine->send(['type' => 'INCREMENT']);

    expect($machine->state->context->get('count'))->toBe(3);
});

// ─── Inline closures still work ──────────────────────────────

it('still handles inline closure behaviors', function (): void {
    // TrafficLightsMachine has doNothingAction as a closure
    $machine = TrafficLightsMachine::create();

    // MULTIPLY uses IsEvenGuard (count starts at 0, which is even) + doNothingAction closures
    $machine->send(['type' => 'MULTIPLY']);

    // MultiplyByTwoAction runs (0*2=0), then closures run without error
    expect($machine->state->context->get('count'))->toBe(0);
});

// ─── runWithState uses container resolution ──────────────────

it('resolves through container when using runWithState', function (): void {
    $state = State::forTesting(['count' => 5]);

    IncrementWithServiceAction::runWithState($state);

    expect($state->context->get('count'))->toBe(6);
});

it('respects mocked services when using runWithState', function (): void {
    $mock = Mockery::mock(CounterService::class);
    $mock->shouldReceive('increment')->with(10)->once()->andReturn(99);
    App::instance(CounterService::class, $mock);

    $state = State::forTesting(['count' => 10]);

    IncrementWithServiceAction::runWithState($state);

    expect($state->context->get('count'))->toBe(99);
});
