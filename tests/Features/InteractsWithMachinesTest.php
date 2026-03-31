<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Scenarios\ScenarioPlayer;
use Tarfinlabs\EventMachine\Scenarios\ScenarioDiscovery;
use Tarfinlabs\EventMachine\Testing\InlineBehaviorFake;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Guards\IsEligibleGuard;

test('tearDown resets ScenarioPlayer overrides (boundClassKeys, inlineKeys, outcomes, isActive)', function (): void {
    // Simulate dirty state — register some overrides
    $reflection = new ReflectionProperty(ScenarioPlayer::class, 'outcomes');
    $reflection->setAccessible(true);
    $reflection->setValue(null, ['some_state' => '@done']);

    // Cleanup (simulates what InteractsWithMachines::tearDown does)
    ScenarioPlayer::cleanupOverrides();

    expect(ScenarioPlayer::getOutcome('some_state'))->toBeNull()
        ->and(ScenarioPlayer::isActive())->toBeFalse();
});

test('tearDown resets ScenarioDiscovery cache', function (): void {
    // Populate the cache
    ScenarioDiscovery::forMachine(ScenarioTestMachine::class);

    // Reset (simulates what InteractsWithMachines::tearDown does)
    ScenarioDiscovery::resetCache();

    // After reset, internal cache should be cleared
    // Verify by using reflection to check the static $cache property
    $reflection = new ReflectionProperty(ScenarioDiscovery::class, 'cache');
    $reflection->setAccessible(true);
    $cache = $reflection->getValue();

    expect($cache)->toBeEmpty();
});
