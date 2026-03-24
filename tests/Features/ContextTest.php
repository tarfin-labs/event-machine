<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\IsOddAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Guards\IsValidatedOddGuard;
use Tarfinlabs\EventMachine\Exceptions\MissingMachineContextException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext;

// === Computed Context Attributes Tests ===

it('can be computed context methods defined', function (): void {
    $context = new TrafficLightsContext();

    expect($context->count)->toBe(0);
    expect($context->isCountEven())->toBeTrue();

    $context->count = 2;

    expect($context->isCountEven())->toBeTrue();
});

// === Required Context Tests ===

// Typed context class for requiredContext tests
class RequiredContextTestContext extends ContextManager
{
    public function __construct(
        public ?int $user_id = null,
        public ?string $user_name = null,
        public bool $settings_enabled = false,
    ) {}
}

class TestBehaviorWithRequiredContext extends InvokableBehavior
{
    public static array $requiredContext = [
        'user_id'          => 'int',
        'user_name'        => 'string',
        'settings_enabled' => 'bool',
    ];

    public function __invoke(): void {}
}

class TestBehaviorWithoutRequiredContext extends InvokableBehavior
{
    public function __invoke(): void {}
}

class TestBehaviorWithExtraRequired extends InvokableBehavior
{
    public static array $requiredContext = [
        'value_a' => 'string',
        'value_b' => 'int',
    ];

    public function __invoke(): void {}
}

class ExtraRequiredContext extends ContextManager
{
    public function __construct(
        public ?string $value_a = null,
        public ?int $value_b = null,
    ) {}
}

test('hasMissingContext returns null when no required context is defined', function (): void {
    $context = GenericContext::from([
        'count' => 1,
    ]);

    $result = TestBehaviorWithoutRequiredContext::hasMissingContext($context);

    expect($result)->toBeNull();
});

test('hasMissingContext returns null when all required context is present', function (): void {
    $context = new RequiredContextTestContext(
        user_id: 1,
        user_name: 'John',
        settings_enabled: true,
    );

    $result = TestBehaviorWithRequiredContext::hasMissingContext($context);

    expect($result)->toBeNull();
});

test('hasMissingContext returns the first missing key when context property is null', function (): void {
    $context = new RequiredContextTestContext(
        user_id: 1,
        // user_name is null (default)
        settings_enabled: true,
    );

    $result = TestBehaviorWithRequiredContext::hasMissingContext($context);

    expect($result)->toBe('user_name');
});

test('hasMissingContext checks type constraints', function (): void {
    // user_id is int but value doesn't matter for type check
    // since typed properties enforce the type at construction time
    $context = new RequiredContextTestContext(
        user_id: 1,
        user_name: 'John',
        settings_enabled: true,
    );

    $result = TestBehaviorWithRequiredContext::hasMissingContext($context);

    expect($result)->toBeNull();
});

test('hasMissingContext returns first missing key for multiple missing fields', function (): void {
    $context = new ExtraRequiredContext();
    // Both value_a and value_b are null

    $result = TestBehaviorWithExtraRequired::hasMissingContext($context);

    expect($result)->toBe('value_a');
});

test('validateRequiredContext throws exception with correct missing key message', function (): void {
    $context = new RequiredContextTestContext(
        user_id: 1,
        // user_name missing
        settings_enabled: true,
    );

    expect(fn () => TestBehaviorWithRequiredContext::validateRequiredContext($context))
        ->toThrow(MissingMachineContextException::class, '`user_name` is missing in context.');
});

test('validateRequiredContext passes when all context is present', function (): void {
    $context = new RequiredContextTestContext(
        user_id: 1,
        user_name: 'John',
        settings_enabled: true,
    );

    expect(TestBehaviorWithRequiredContext::validateRequiredContext($context))->toBeNull();
    expect(fn () => TestBehaviorWithRequiredContext::validateRequiredContext($context))->not->toThrow(MissingMachineContextException::class);
});

test('validateRequiredContext throws exception for empty context when requirements exist', function (): void {
    $context = new RequiredContextTestContext();

    expect(fn () => TestBehaviorWithRequiredContext::validateRequiredContext($context))
        ->toThrow(MissingMachineContextException::class);
});

// === Context Values Required for Guards and Actions ===

test('context values can be required for guards and actions inside machine', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'required_context_machine',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => [
                        'INC' => [
                            'target'  => 'idle',
                            'guards'  => IsValidatedOddGuard::class,
                            'actions' => IsOddAction::class,
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'context' => TrafficLightsContext::class,
            'guards'  => [
                IsValidatedOddGuard::class,
            ],
            'actions' => [
                IsOddAction::class,
            ],
        ],
    );

    $machine = Machine::create(definition: $definition);

    expect(fn () => $machine->send(['type' => 'INC', 'payload' => ['value' => 1]]))
        ->toThrow(MissingMachineContextException::class);
});
