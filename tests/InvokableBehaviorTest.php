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
