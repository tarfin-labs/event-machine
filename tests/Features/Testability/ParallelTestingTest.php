<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

// ─── withoutParallelDispatch() ──────────────────────────────

it('disables parallel dispatch via config', function (): void {
    config(['machine.parallel_dispatch.enabled' => true]);

    $test = TrafficLightsMachine::test()
        ->withoutParallelDispatch();

    expect(config('machine.parallel_dispatch.enabled'))->toBeFalse();
    expect($test)->toBeInstanceOf(TestMachine::class);
});

it('does not leak config to subsequent tests', function (): void {
    // Previous test sets enabled=true then withoutParallelDispatch() sets it to false.
    // Laravel resets config between tests — this should see the default (false from config/machine.php).
    // The key insight: config is reset between tests because Orchestra Testbench
    // recreates the application, so withoutParallelDispatch() doesn't leak.
    $default = config('machine.parallel_dispatch.enabled');
    expect($default)->toBeFalse();

    // Now set it to true, use withoutParallelDispatch(), and verify it resets next test
    config(['machine.parallel_dispatch.enabled' => true]);
    expect(config('machine.parallel_dispatch.enabled'))->toBeTrue();
});

it('verifies config is restored after previous test set it to true', function (): void {
    // Previous test explicitly set enabled=true. If config leaked, this would be true.
    expect(config('machine.parallel_dispatch.enabled'))->toBeFalse();
});

it('is chainable with other configuration methods', function (): void {
    $test = TrafficLightsMachine::test()
        ->withoutPersistence()
        ->withoutParallelDispatch();

    expect($test->machine()->definition->shouldPersist)->toBeFalse();
    expect(config('machine.parallel_dispatch.enabled'))->toBeFalse();
});

// ─── assertRegionState() ────────────────────────────────────

it('asserts region state from value array', function (): void {
    // Create a test machine with inline definition containing parallel-like state values
    $test = TestMachine::define([
        'initial' => 'active',
        'states'  => [
            'active' => [],
        ],
    ]);

    // Manually set state value to simulate parallel regions
    $test->state()->value = [
        'machine.processing.payment.pending',
        'machine.processing.inventory.checking',
    ];

    $test->assertRegionState('payment', 'pending');
    $test->assertRegionState('inventory', 'checking');
});

it('rejects partial region name matches in assertRegionState', function (): void {
    $test = TestMachine::define([
        'initial' => 'active',
        'states'  => [
            'active' => [],
        ],
    ]);

    $test->state()->value = [
        'machine.processing.payment.pending',
    ];

    // 'pay' is a substring of 'payment' — should NOT match
    expect(fn () => $test->assertRegionState('pay', 'pending'))
        ->toThrow(Exception::class);
});

it('rejects partial state name matches in assertRegionState', function (): void {
    $test = TestMachine::define([
        'initial' => 'active',
        'states'  => [
            'active' => [],
        ],
    ]);

    $test->state()->value = [
        'machine.processing.payment.pending',
    ];

    // 'pend' is a substring of 'pending' — should NOT match
    expect(fn () => $test->assertRegionState('payment', 'pend'))
        ->toThrow(Exception::class);
});
