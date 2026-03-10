<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\TestabilityMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;
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
    expect($test->state())->toBeInstanceOf(\Tarfinlabs\EventMachine\Actor\State::class);
    expect($test->context())->toBeInstanceOf(\Tarfinlabs\EventMachine\ContextManager::class);
});

// ─── Cleanup ─────────────────────────────────────────────────

it('resets fakes via resetFakes()', function (): void {
    $test = TrafficLightsMachine::test()
        ->faking([IncrementAction::class]);

    expect(IncrementAction::isFaked())->toBeTrue();

    $test->resetFakes();

    expect(IncrementAction::isFaked())->toBeFalse();
});
