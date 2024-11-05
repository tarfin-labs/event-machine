<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\IsOddAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Guards\IsValidatedOddGuard;
use Tarfinlabs\EventMachine\Exceptions\MissingMachineContextException;

test('context values can be required for guards and actions', function (): void {
class TestBehaviorWithRequiredContext extends InvokableBehavior
{
    public static array $requiredContext = [
        'user.id'          => 'integer',
        'user.name'        => 'string',
        'settings.enabled' => 'boolean',
    ];

    public function __invoke(): void {}
}

class TestBehaviorWithoutRequiredContext extends InvokableBehavior
{
    public function __invoke(): void {}
}

class TestBehaviorWithNestedRequiredContext extends InvokableBehavior
{
    public static array $requiredContext = [
        'deeply.nested.value'   => 'string',
        'another.nested.number' => 'integer',
    ];

    public function __invoke(): void {}
}

test('hasMissingContext returns null when no required context is defined', function (): void {
    $behavior = new TestBehaviorWithoutRequiredContext();
    $context  = new ContextManager([
        'some' => 'value',
    ]);

    expect($behavior->hasMissingContext($context))->toBeNull();
});

test('hasMissingContext returns null when all required context is present', function (): void {
    $behavior = new TestBehaviorWithRequiredContext();
    $context  = new ContextManager([
        'user' => [
            'id'   => 1,
            'name' => 'John',
        ],
        'settings' => [
            'enabled' => true,
        ],
    ]);

    expect($behavior->hasMissingContext($context))->toBeNull();
});

test('hasMissingContext returns the first missing key path when context is missing', function (): void {
    $behavior = new TestBehaviorWithRequiredContext();
    $context  = new ContextManager([
        'user' => [
            'id' => 1,
            // name is missing
        ],
        'settings' => [
            'enabled' => true,
        ],
    ]);

    expect($behavior->hasMissingContext($context))->toBe('user.name');
});
    $machineDefinition = MachineDefinition::define(config: [
        'context' => [
            'counts' => [
                'oddCount' => null,
            ],
        ],
        'states' => [
            'stateA' => [
                'on' => [
                    'EVENT' => [
                        'target' => 'stateB',
                        'guards' => IsValidatedOddGuard::class,
                    ],
                    'EVENT2' => [
                        'target'  => 'stateB',
                        'actions' => IsOddAction::class,
                    ],
                ],
            ],
            'stateB' => [],
        ],
    ]);

    expect(fn () => $machineDefinition->transition(event: ['type' => 'EVENT']))
        ->toThrow(
            exception: MissingMachineContextException::class,
            exceptionMessage: '`counts.oddCount` is missing in context.',
        );

    expect(fn () => $machineDefinition->transition(event: ['type' => 'EVENT2']))
        ->toThrow(
            exception: MissingMachineContextException::class,
            exceptionMessage: '`counts.oddCount` is missing in context.',
        );
});
