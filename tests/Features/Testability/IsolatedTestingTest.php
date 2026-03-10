<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\CounterService;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\Actions\LogEntryAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\AddValueAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\IncrementAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\Guards\IsCountPositiveGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\Actions\DoubleCountCalculator;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\Actions\IncrementWithServiceAction;

afterEach(function (): void {
    IncrementAction::resetAllFakes();
});

// ─── State::forTesting() ─────────────────────────────────────

it('creates state from array context', function (): void {
    $state = State::forTesting(['count' => 5, 'name' => 'test']);

    expect($state->context)->toBeInstanceOf(ContextManager::class);
    expect($state->context->get('count'))->toBe(5);
    expect($state->context->get('name'))->toBe('test');
});

it('creates state from ContextManager instance', function (): void {
    $context = new ContextManager(['count' => 10]);
    $state   = State::forTesting($context);

    expect($state->context)->toBe($context);
    expect($state->context->get('count'))->toBe(10);
});

it('creates state with empty context by default', function (): void {
    $state = State::forTesting();

    expect($state->context)->toBeInstanceOf(ContextManager::class);
    expect($state->value)->toBe([]);
    expect($state->history)->not->toBeNull();
});

// ─── runWithState() — guards ─────────────────────────────────

it('runs guard with state and returns boolean', function (): void {
    $state = State::forTesting(['count' => 5]);
    expect(IsCountPositiveGuard::runWithState($state))->toBeTrue();

    $state = State::forTesting(['count' => -1]);
    expect(IsCountPositiveGuard::runWithState($state))->toBeFalse();
});

// ─── runWithState() — actions ────────────────────────────────

it('runs action that modifies context', function (): void {
    $state = State::forTesting(['entered' => false]);

    LogEntryAction::runWithState($state);

    expect($state->context->get('entered'))->toBeTrue();
});

it('runs calculator that modifies context', function (): void {
    $state = State::forTesting(['count' => 5]);

    DoubleCountCalculator::runWithState($state);

    expect($state->context->get('count'))->toBe(10);
});

// ─── runWithState() — constructor DI ─────────────────────────

it('resolves constructor DI service when using runWithState', function (): void {
    $state = State::forTesting(['count' => 7]);

    IncrementWithServiceAction::runWithState($state);

    expect($state->context->get('count'))->toBe(8);
});

it('uses mocked constructor DI service with runWithState', function (): void {
    $mock = Mockery::mock(CounterService::class);
    $mock->shouldReceive('increment')->with(7)->once()->andReturn(100);
    App::instance(CounterService::class, $mock);

    $state = State::forTesting(['count' => 7]);

    IncrementWithServiceAction::runWithState($state);

    expect($state->context->get('count'))->toBe(100);
});

// ─── runWithState() — with event behavior ────────────────────

it('passes event behavior to action via runWithState', function (): void {
    $state = State::forTesting(new \Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext(count: 0, modelA: new \Spatie\LaravelData\Optional()));
    $event = new \Tarfinlabs\EventMachine\Definition\EventDefinition(type: 'ADD_VALUE', payload: ['value' => 42]);

    AddValueAction::runWithState($state, eventBehavior: $event);

    expect($state->context->count)->toBe(42);
});

// ─── runWithState() — faked behaviors ────────────────────────

it('respects faked behavior via runWithState', function (): void {
    IncrementAction::shouldRun()->withAnyArgs()->once();

    $state = State::forTesting(new \Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext(count: 0, modelA: new \Spatie\LaravelData\Optional()));

    IncrementAction::runWithState($state);

    IncrementAction::assertRan();
    // Mock didn't actually modify context
    expect($state->context->count)->toBe(0);
});

it('uses spy to verify faked behavior was called with runWithState', function (): void {
    IncrementAction::allowToRun();

    $state = State::forTesting(new \Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext(count: 5, modelA: new \Spatie\LaravelData\Optional()));

    IncrementAction::runWithState($state);

    IncrementAction::assertRan();
});
