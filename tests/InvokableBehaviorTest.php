<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Events\SEvent;

test('invokable behavior shouldLog property defaults to false', function (): void {
    $behavior = new class() extends InvokableBehavior {
        public function __invoke(): void {}
    };

    // Test that shouldLog defaults to false (not true)
    expect($behavior->shouldLog)->toBeFalse();
    expect($behavior->shouldLog)->not->toBeTrue();
});

test('hasMissingContext returns null for empty required context', function (): void {
    $behavior = new class() extends InvokableBehavior {
        public static array $requiredContext = [];

        public function __invoke(): void {}
    };

    $contextManager = new ContextManager([]);

    // Test that when requiredContext is empty, method returns null
    expect($behavior::hasMissingContext($contextManager))->toBeNull();

    // Test with non-empty requiredContext
    $behaviorWithRequirements = new class() extends InvokableBehavior {
        public static array $requiredContext = ['user_id' => 'string'];

        public function __invoke(): void {}
    };

    // Should return the missing key when context is missing
    expect($behaviorWithRequirements::hasMissingContext($contextManager))->toBe('user_id');

    // Should return null when context has required data
    $contextWithData = new ContextManager(['user_id' => '123']);
    expect($behaviorWithRequirements::hasMissingContext($contextWithData))->toBeNull();
});

test('injectInvokableBehaviorParameters handles reflection correctly', function (): void {
    $machine = MachineDefinition::define([
        'initial' => 'idle',
        'states'  => [
            'idle' => [],
        ],
    ]);

    $state = $machine->getInitialState();
    $event = new SEvent();

    // Test with InvokableBehavior instance (instanceof should be true)
    $behaviorInstance = new class() extends InvokableBehavior {
        public function __invoke(State $state): State
        {
            return $state;
        }
    };

    $params = InvokableBehavior::injectInvokableBehaviorParameters(
        $behaviorInstance,
        $state,
        $event
    );

    expect($params)->toHaveCount(1);
    expect($params[0])->toBe($state);

    // Test with regular callable (instanceof should be false)
    $callableFunction = function (State $state) {
        return $state;
    };

    $params2 = InvokableBehavior::injectInvokableBehaviorParameters(
        $callableFunction,
        $state,
        $event
    );

    expect($params2)->toHaveCount(1);
    expect($params2[0])->toBe($state);
});

test('injectInvokableBehaviorParameters handles union types correctly', function (): void {
    $machine = MachineDefinition::define([
        'initial' => 'idle',
        'states'  => [
            'idle' => [],
        ],
    ]);

    $state = $machine->getInitialState();
    $event = new SEvent();

    // Test with function that has union type parameter
    $unionTypeFunction = function (ContextManager|State $param) {
        return $param;
    };

    // This should use the first type in the union (ContextManager) and return the state's context
    $params = InvokableBehavior::injectInvokableBehaviorParameters(
        $unionTypeFunction,
        $state,
        $event
    );

    expect($params)->toHaveCount(1);
    expect($params[0])->toBe($state->context);

    // Test with regular single type parameter
    $singleTypeFunction = function (State $param) {
        return $param;
    };

    $params2 = InvokableBehavior::injectInvokableBehaviorParameters(
        $singleTypeFunction,
        $state,
        $event
    );

    expect($params2)->toHaveCount(1);
    expect($params2[0])->toBe($state);
});

test('constructor initializes eventQueue correctly', function (): void {
    $behavior = new class(null) extends InvokableBehavior {
        public function __invoke(): void {}

        public function getEventQueue()
        {
            return $this->eventQueue;
        }
    };

    // Test that when eventQueue is null, a new Collection is created
    expect($behavior->getEventQueue())->not->toBeNull();
    expect($behavior->getEventQueue())->toHaveCount(0);
});
