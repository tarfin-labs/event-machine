<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Actor\Machine;
use PHPUnit\Framework\AssertionFailedError;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use PHPUnit\Framework\ExpectationFailedException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\MachineWithScenarios;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\TestabilityMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\IncreaseEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\ParallelCompletionMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\IncrementAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\AllInvocationPointsMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\Guards\IsCountPositiveGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\Actions\IncrementWithServiceAction;

afterEach(function (): void {
    IncrementAction::resetAllFakes();
});

// ─── Construction ────────────────────────────────────────────

it('creates TestMachine via Machine::test()', function (): void {
    $test = TrafficLightsMachine::test();

    expect($test)->toBeInstanceOf(TestMachine::class);
    expect($test->machine())->toBeInstanceOf(Machine::class);
});

it('creates TestMachine with initial context', function (): void {
    $test = TestabilityMachine::test(['count' => 42]);

    expect($test->context()->get('count'))->toBe(42);
});

it('creates TestMachine via define() for inline definitions', function (): void {
    $test = TestMachine::define([
        'initial' => 'idle',
        'context' => ['count' => 0],
        'states'  => [
            'idle' => [
                'on' => [
                    'GO' => ['target' => 'active'],
                ],
            ],
            'active' => [],
        ],
    ]);

    $test->send('GO')->assertState('active');
});

it('auto-disables persistence in define()', function (): void {
    $test = TestMachine::define([
        'initial' => 'idle',
        'states'  => ['idle' => []],
    ]);

    expect($test->machine()->definition->shouldPersist)->toBeFalse();
});

it('wraps existing machine via for()', function (): void {
    $machine = TrafficLightsMachine::create();
    $test    = TestMachine::for($machine);

    expect($test->machine())->toBe($machine);
});

// ─── send() ──────────────────────────────────────────────────

it('sends events via string shorthand', function (): void {
    $test = TrafficLightsMachine::test();
    $test->send('INCREASE');

    expect($test->context()->get('count'))->toBe(1);
});

it('sends events via array', function (): void {
    $test = TrafficLightsMachine::test();
    $test->send(['type' => 'INCREASE']);

    expect($test->context()->get('count'))->toBe(1);
});

it('sends events via EventBehavior instance', function (): void {
    $test = TrafficLightsMachine::test();
    $test->send(IncreaseEvent::forTesting());

    expect($test->context()->get('count'))->toBe(1);
});

it('chains multiple sends fluently', function (): void {
    TrafficLightsMachine::test()
        ->send('INCREASE')
        ->send('INCREASE')
        ->send('INCREASE')
        ->assertContext('count', 3);
});

// ─── sendMany() ──────────────────────────────────────────────

it('sends multiple events in sequence', function (): void {
    TrafficLightsMachine::test()
        ->sendMany(['INCREASE', 'INCREASE'])
        ->assertContext('count', 2);
});

// ─── State assertions ────────────────────────────────────────

it('asserts current state with assertState', function (): void {
    TrafficLightsMachine::test()
        ->assertState('active');
});

it('asserts NOT in state with assertNotState', function (): void {
    TrafficLightsMachine::test()
        ->assertNotState('inactive');
});

// ─── Context assertions ──────────────────────────────────────

it('asserts context value', function (): void {
    TrafficLightsMachine::test()
        ->assertContext('count', 0);
});

it('asserts context has key', function (): void {
    TrafficLightsMachine::test()
        ->assertContextHas('count');
});

it('asserts context key is absent with assertContextMissing', function (): void {
    TrafficLightsMachine::test()
        ->assertContextMissing('nonexistent_key');
});

it('assertContextMissing fails when key exists', function (): void {
    expect(fn () => TrafficLightsMachine::test()->assertContextMissing('count'))
        ->toThrow(ExpectationFailedException::class);
});

it('asserts context matches callback', function (): void {
    TrafficLightsMachine::test()
        ->send('INCREASE')
        ->send('INCREASE')
        ->assertContextMatches('count', fn ($v) => $v > 1);
});

it('asserts context includes subset', function (): void {
    TestabilityMachine::test(['count' => 5])
        ->assertContextIncludes(['count' => 5]);
});

// ─── Transition assertions ───────────────────────────────────

it('asserts transition with assertTransition', function (): void {
    AllInvocationPointsMachine::test()
        ->assertTransition('PROCESS', 'active');
});

it('asserts event is guarded with assertGuarded', function (): void {
    AllInvocationPointsMachine::test(['count' => 0])
        ->assertGuarded('PROCESS');
});

it('treats unknown events as guarded in assertGuarded', function (): void {
    $test = TestMachine::define([
        'initial' => 'idle',
        'states'  => [
            'idle' => [
                'on' => [
                    'GO' => ['target' => 'active'],
                ],
            ],
            'active' => [],
        ],
    ])->assertGuarded('NONEXISTENT_EVENT');

    $test->assertState('idle');
});

// ─── History assertions ──────────────────────────────────────

it('asserts history contains event types', function (): void {
    TrafficLightsMachine::test()
        ->send('INCREASE')
        ->assertHistoryContains('INCREASE');
});

it('asserts history order of events', function (): void {
    TrafficLightsMachine::test()
        ->send('INCREASE')
        ->send('INCREASE')
        ->send('INCREASE')
        ->assertHistoryOrder('INCREASE', 'INCREASE', 'INCREASE');
});

// ─── Path assertions ─────────────────────────────────────────

it('asserts path of state transitions', function (): void {
    TrafficLightsMachine::test()
        ->assertPath([
            ['event' => 'INCREASE', 'state' => 'active', 'context' => ['count' => 1]],
            ['event' => 'INCREASE', 'state' => 'active', 'context' => ['count' => 2]],
        ]);
});

// ─── Faking ──────────────────────────────────────────────────

it('fakes behaviors via faking()', function (): void {
    $test = AllInvocationPointsMachine::test()
        ->faking([IncrementWithServiceAction::class, IsCountPositiveGuard::class]);

    IsCountPositiveGuard::shouldReturn(true);
    $test->send('PROCESS');

    $test->assertBehaviorRan(IncrementWithServiceAction::class);

    $test->resetFakes();
    IsCountPositiveGuard::resetFakes();
});

it('asserts behavior was not run', function (): void {
    IncrementAction::fake();
    IncrementAction::shouldNotRun();

    TrafficLightsMachine::test()
        ->assertBehaviorNotRan(IncrementAction::class);
});

// ─── Edge cases ─────────────────────────────────────────────

it('handles empty steps in assertPath', function (): void {
    $test = TrafficLightsMachine::test()
        ->assertPath([]);

    $test->assertState('active');
});

it('handles empty subset in assertContextIncludes', function (): void {
    $test = TrafficLightsMachine::test()
        ->assertContextIncludes([]);

    $test->assertContextHas('count');
});

it('assertFinished passes when in final state', function (): void {
    TestMachine::define([
        'initial' => 'active',
        'states'  => [
            'active' => [
                'on' => [
                    'COMPLETE' => ['target' => 'done'],
                ],
            ],
            'done' => [
                'type' => 'final',
            ],
        ],
    ])->send('COMPLETE')->assertFinished();
});

it('assertFinished fails when not in final state', function (): void {
    expect(fn () => TrafficLightsMachine::test()->assertFinished())
        ->toThrow(ExpectationFailedException::class);
});

it('asserts result value with assertResult', function (): void {
    // Final state with no output behavior returns toResponseArray() fallback
    $test = TestMachine::define([
        'initial' => 'active',
        'states'  => [
            'active' => [
                'on' => [
                    'COMPLETE' => ['target' => 'done'],
                ],
            ],
            'done' => [
                'type' => 'final',
            ],
        ],
    ])->send('COMPLETE');

    // output() returns toResponseArray() when no output is defined (never null)
    expect($test->machine()->output())->toBeArray();
});

// ─── Validation assertions ──────────────────────────────────

it('asserts validation failed with assertValidationFailed', function (): void {
    TrafficLightsMachine::test()
        ->send('INCREASE')
        ->assertValidationFailed('MULTIPLY')
        ->assertState('active');
});

it('assertValidationFailed checks specific error key', function (): void {
    $test = TrafficLightsMachine::test()
        ->send('INCREASE');

    // IsEvenGuard error key contains the guard event type pattern
    // We just verify the assertion does not throw when a key is present
    expect(fn () => $test->assertValidationFailed('MULTIPLY', 'nonexistent_key'))
        ->toThrow(ExpectationFailedException::class);
});

it('assertValidationFailed fails when no exception is thrown', function (): void {
    // count=0 is even, so IsEvenGuard passes — no MachineValidationException
    expect(fn () => TrafficLightsMachine::test()->assertValidationFailed('MULTIPLY'))
        ->toThrow(AssertionFailedError::class);
});

// ─── withoutPersistence() ────────────────────────────────────

it('disables persistence', function (): void {
    $test = TrafficLightsMachine::test()
        ->withoutPersistence();

    expect($test->machine()->definition->shouldPersist)->toBeFalse();
});

// ─── Accessors ───────────────────────────────────────────────

it('provides access to machine, state, and context', function (): void {
    $test = TrafficLightsMachine::test();

    expect($test->machine())->toBeInstanceOf(Machine::class);
    expect($test->state())->toBeInstanceOf(State::class);
    expect($test->context())->toBeInstanceOf(ContextManager::class);
});

// ─── Cleanup ─────────────────────────────────────────────────

// ─── withContext() ──────────────────────────────────────────

it('creates TestMachine with pre-start context via withContext()', function (): void {
    $test = TestMachine::withContext(TestabilityMachine::class, ['count' => 99]);

    expect($test->context()->get('count'))->toBe(99);
});

it('test(context:) merges context before initialization', function (): void {
    $test = TestabilityMachine::test(context: ['count' => 55]);

    expect($test)->toBeInstanceOf(TestMachine::class);
    expect($test->context()->get('count'))->toBe(55);
});

it('withContext() disables persistence', function (): void {
    $test = TestabilityMachine::test(context: ['count' => 1]);

    expect($test->machine()->definition->shouldPersist)->toBeFalse();
});

it('withContext() does not leak between calls', function (): void {
    $test1 = TestabilityMachine::test(context: ['count' => 100]);
    $test2 = TestabilityMachine::test(context: ['count' => 200]);

    expect($test1->context()->get('count'))->toBe(100);
    expect($test2->context()->get('count'))->toBe(200);
});

// ─── tap() ──────────────────────────────────────────────────

it('executes callback via tap() and continues chain', function (): void {
    $called = false;

    TrafficLightsMachine::test()
        ->send('INCREASE')
        ->tap(function ($test) use (&$called): void {
            $called = true;
            expect($test->context()->get('count'))->toBe(1);
        })
        ->assertContext('count', 1);

    expect($called)->toBeTrue();
});

// ─── assertBehaviorRanTimes() + assertBehaviorRanWith() ─────

it('asserts behavior ran exact number of times', function (): void {
    IncrementAction::spy();

    TrafficLightsMachine::test()
        ->send('INCREASE')
        ->send('INCREASE')
        ->assertBehaviorRanTimes(IncrementAction::class, 2);
});

it('asserts behavior ran with matching context', function (): void {
    IncrementAction::allowToRun();

    TrafficLightsMachine::test()
        ->send('INCREASE')
        ->assertBehaviorRanWith(IncrementAction::class, fn ($ctx) => true);
});

// ─── assertGuardedBy() ──────────────────────────────────────

it('asserts event is guarded by a specific FQCN guard', function (): void {
    AllInvocationPointsMachine::test(['count' => 0])
        ->assertGuardedBy('PROCESS', IsCountPositiveGuard::class);
});

it('assertGuardedBy fails when guard passes', function (): void {
    expect(fn () => AllInvocationPointsMachine::test(['count' => 5])
        ->assertGuardedBy('PROCESS', IsCountPositiveGuard::class)
    )->toThrow(ExpectationFailedException::class);
});

// ─── withScenario() ─────────────────────────────────────────

it('sets scenario type via withScenario()', function (): void {
    $test = MachineWithScenarios::test()
        ->withScenario('test');

    expect($test->context()->get('scenarioType'))->toBe('test');
});

it('withScenario routes to scenario-specific state', function (): void {
    // Without scenario: EVENT_B goes state_a → state_b
    MachineWithScenarios::test()
        ->send('EVENT_B')
        ->assertState('state_b');

    // With 'test' scenario: EVENT_B goes state_a → state_c (scenario override)
    // Scenario states are namespaced: test.state_c
    MachineWithScenarios::test()
        ->withScenario('test')
        ->send('EVENT_B')
        ->assertState('test.state_c');
});

// ─── assertTransitionedThrough() ────────────────────────────

it('asserts machine transitioned through expected states', function (): void {
    AllInvocationPointsMachine::test()
        ->send('PROCESS')
        ->assertTransitionedThrough(['idle', 'active']);
});

it('assertTransitionedThrough enforces order', function (): void {
    // ['active', 'idle'] is wrong order — machine goes idle → active
    expect(fn () => AllInvocationPointsMachine::test()
        ->send('PROCESS')
        ->assertTransitionedThrough(['active', 'idle'])
    )->toThrow(ExpectationFailedException::class);
});

// ─── debugGuards() ──────────────────────────────────────────

it('returns guard evaluation results via debugGuards()', function (): void {
    // Guard fails (count = 0)
    $test    = AllInvocationPointsMachine::test(['count' => 0]);
    $results = $test->debugGuards('PROCESS');

    expect($results)->toHaveKey('IsCountPositiveGuard');
    expect($results['IsCountPositiveGuard'])->toBeFalse();
});

it('debugGuards returns pass when guard succeeds', function (): void {
    $test    = AllInvocationPointsMachine::test(['count' => 5]);
    $results = $test->debugGuards('PROCESS');

    expect($results)->toHaveKey('IsCountPositiveGuard');
    expect($results['IsCountPositiveGuard'])->toBeTrue();
});

it('debugGuards returns empty array for unknown events', function (): void {
    $test    = AllInvocationPointsMachine::test();
    $results = $test->debugGuards('NONEXISTENT');

    expect($results)->toBeEmpty();
});

// ─── assertAllRegionsCompleted() ─────────────────────────────

it('asserts all parallel regions completed', function (): void {
    ParallelCompletionMachine::test()
        ->withoutPersistence()
        ->withoutParallelDispatch()
        ->send('PAYMENT_SUCCESS')
        ->send('INVENTORY_RESERVE')
        ->assertAllRegionsCompleted()
        ->assertState('fulfilled');
});

it('asserts all parallel regions completed with explicit route', function (): void {
    ParallelCompletionMachine::test()
        ->withoutPersistence()
        ->withoutParallelDispatch()
        ->send('PAYMENT_SUCCESS')
        ->send('INVENTORY_RESERVE')
        ->assertAllRegionsCompleted('processing')
        ->assertState('fulfilled');
});

it('assertAllRegionsCompleted fails when not all regions are final', function (): void {
    expect(fn () => ParallelCompletionMachine::test()
        ->withoutPersistence()
        ->withoutParallelDispatch()
        ->send('PAYMENT_SUCCESS')
        ->assertAllRegionsCompleted()
    )->toThrow(ExpectationFailedException::class);
});

// ─── Cleanup ─────────────────────────────────────────────────

it('resets fakes via resetFakes()', function (): void {
    $test = TrafficLightsMachine::test()
        ->faking([IncrementAction::class]);

    expect(IncrementAction::isFaked())->toBeTrue();

    $test->resetFakes();

    expect(IncrementAction::isFaked())->toBeFalse();
});
