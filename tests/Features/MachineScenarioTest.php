<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Exceptions\ScenarioConfigurationException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Events\ApproveEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios\HappyPathScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios\ContinueLoopScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios\ParameterizedScenario;

// ── 1. Valid scenario — no exception ─────────────────────────────────────────

test('valid scenario with all properties set — no exception thrown', function (): void {
    $scenario = new HappyPathScenario();

    expect($scenario->machine())->toBe(ScenarioTestMachine::class)
        ->and($scenario->source())->toBe('idle')
        ->and($scenario->target())->toBe('approved')
        ->and($scenario->description())->not->toBeEmpty();
});

// ── 2-4. Missing/empty properties ────────────────────────────────────────────

test('missing $machine throws ScenarioConfigurationException', function (): void {
    expect(function (): void {
        new class() extends MachineScenario {
            protected string $source      = 'idle';
            protected string $event       = '@start';
            protected string $target      = 'done';
            protected string $description = 'test';
        };
    })->toThrow(ScenarioConfigurationException::class);
});

test('missing $target throws ScenarioConfigurationException', function (): void {
    expect(function (): void {
        new class() extends MachineScenario {
            protected string $machine = ScenarioTestMachine::class;
            protected string $source  = 'idle';
            protected string $event   = '@start';

            // $target intentionally missing
            protected string $description = 'Missing target';
        };
    })->toThrow(ScenarioConfigurationException::class);
});

test('empty string property throws ScenarioConfigurationException', function (): void {
    expect(function (): void {
        new class() extends MachineScenario {
            protected string $machine     = 'SomeMachine';
            protected string $source      = '';
            protected string $event       = '@start';
            protected string $target      = 'done';
            protected string $description = 'test';
        };
    })->toThrow(ScenarioConfigurationException::class);
});

// ── 5. slug() ────────────────────────────────────────────────────────────────

test('slug() derives kebab-case from class name', function (): void {
    $scenario = new HappyPathScenario();

    expect($scenario->slug())->toBe('happy-path-scenario');
});

// ── 6-7. eventType() ────────────────────────────────────────────────────────

test('eventType() resolves EventBehavior FQCN via getType()', function (): void {
    $scenario = new ContinueLoopScenario();
    // ContinueLoopScenario has $event = ApproveEvent::class

    expect($scenario->eventType())->toBe('APPROVE');
});

test('eventType() returns plain string as-is', function (): void {
    $scenario = new HappyPathScenario();
    // HappyPathScenario has $event = MachineScenario::START = '@start'

    expect($scenario->eventType())->toBe('@start');
});

// ── 8-9. hydrateParams() ────────────────────────────────────────────────────

test('hydrateParams() passes validation and stores values', function (): void {
    $scenario = new ParameterizedScenario();
    $scenario->hydrateParams(['amount' => 42, 'note' => 'test note']);

    expect($scenario->validatedParams())->toBe(['amount' => 42, 'note' => 'test note']);
});

test('hydrateParams() fails validation throws ScenarioConfigurationException', function (): void {
    $scenario = new ParameterizedScenario();

    // amount is required but missing
    expect(fn () => $scenario->hydrateParams(['note' => 'test']))
        ->toThrow(ScenarioConfigurationException::class);
});

// ── 10-11. param() ──────────────────────────────────────────────────────────

test('param() returns hydrated value after hydrateParams', function (): void {
    $scenario = new ParameterizedScenario();
    $scenario->hydrateParams(['amount' => 99]);

    // Use reflection to access protected param()
    $reflection = new ReflectionMethod($scenario, 'param');
    $reflection->setAccessible(true);

    expect($reflection->invoke($scenario, 'amount'))->toBe(99);
});

test('param() returns default when key not present', function (): void {
    $scenario = new ParameterizedScenario();
    $scenario->hydrateParams(['amount' => 1]);

    $reflection = new ReflectionMethod($scenario, 'param');
    $reflection->setAccessible(true);

    expect($reflection->invoke($scenario, 'missing_key', 'default_val'))->toBe('default_val');
});

// ── 12. Rich param definitions ──────────────────────────────────────────────

test('rich param definitions (rules key) extracted correctly, plain arrays passed through', function (): void {
    $scenario = new ParameterizedScenario();
    $params   = $scenario->resolvedParams();

    // 'amount' is plain array: ['required', 'integer', 'min:1']
    expect($params['amount'])->toBe(['required', 'integer', 'min:1']);

    // 'note' is rich definition with 'rules' key
    expect($params['note'])->toHaveKey('rules')
        ->and($params['note']['rules'])->toBe(['nullable', 'string', 'max:255'])
        ->and($params['note']['type'])->toBe('string');
});

// ── 13. resolvedPlan() ──────────────────────────────────────────────────────

test('resolvedPlan() delegates to plan() — concrete scenario returns expected entries', function (): void {
    $scenario = new HappyPathScenario();
    $plan     = $scenario->resolvedPlan();

    expect($plan)->toHaveKey('routing')
        ->and($plan)->toHaveKey('processing')
        ->and($plan)->toHaveKey('reviewing')
        ->and($plan['processing'])->toBe('@done');
});

// ── 14. Empty plan + empty params ───────────────────────────────────────────

test('empty plan() and no params — both return [], valid scenario with no overrides', function (): void {
    $scenario = new ContinueLoopScenario();

    expect($scenario->resolvedPlan())->toBe([])
        ->and($scenario->validatedParams())->toBe([])
        ->and($scenario->resolvedParams())->toBe([]);
});
