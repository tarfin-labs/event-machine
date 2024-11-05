<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\IsOddAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Guards\IsValidatedOddGuard;
use Tarfinlabs\EventMachine\Exceptions\MissingMachineContextException;

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

test('hasMissingContext handles deeply nested context paths', function (): void {
    $behavior = new TestBehaviorWithNestedRequiredContext();

    // All required context present
    $completeContext = new ContextManager([
        'deeply' => [
            'nested' => [
                'value' => 'test',
            ],
        ],
        'another' => [
            'nested' => [
                'number' => 42,
            ],
        ],
    ]);

    expect($behavior->hasMissingContext($completeContext))->toBeNull();

    // Missing nested context
    $incompleteContext = new ContextManager([
        'deeply' => [
            'nested' => [
                'value' => 'test',
            ],
        ],
        // another.nested.number is missing
    ]);

    expect($behavior->hasMissingContext($incompleteContext))->toBe('another.nested.number');
});

test('hasMissingContext returns first missing key for multiple missing fields', function (): void {
    $behavior = new TestBehaviorWithRequiredContext();
    $context  = new ContextManager([
        'settings' => [
            'enabled' => true,
        ],
        // user.id and user.name both missing
    ]);

    expect($behavior->hasMissingContext($context))->toBe('user.id');
});

test('hasMissingContext checks type constraints', function (): void {
    $behavior = new TestBehaviorWithRequiredContext();
    $context  = new ContextManager([
        'user' => [
            'id'   => 'not_an_integer', // Wrong type
            'name' => 'John',
        ],
        'settings' => [
            'enabled' => true,
        ],
    ]);

    expect($behavior->hasMissingContext($context))->toBe('user.id');
});

test('validateRequiredContext throws exception with correct missing key message', function (): void {
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

    expect(fn () => $behavior->validateRequiredContext($context))
        ->toThrow(MissingMachineContextException::class, '`user.name` is missing in context.');
});

test('validateRequiredContext passes when all context is present', function (): void {
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

    expect($behavior->validateRequiredContext($context))->toBeNull();
    expect(fn () => $behavior->validateRequiredContext($context))->not->toThrow(MissingMachineContextException::class);
});

test('validateRequiredContext throws exception for empty context when requirements exist', function (): void {
    $behavior = new TestBehaviorWithRequiredContext();
    $context  = new ContextManager([]);

    expect(fn () => $behavior->validateRequiredContext($context))
        ->toThrow(MissingMachineContextException::class, '`user.id` is missing in context.');
});

test('context values can be required for guards and actions inside machine', function (): void {
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
