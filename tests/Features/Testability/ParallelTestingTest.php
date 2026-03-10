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
