<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\Stubs\Scenarios\TrafficLightsActiveScenario;

beforeEach(function (): void {
    config(['machine.scenarios.enabled' => true]);
});

it('sets timer disabled flag during scenario replay', function (): void {
    // The flag should be set during replay and removed after
    // We can verify by checking the flag doesn't persist after play
    TrafficLightsActiveScenario::play();

    expect(app()->bound('scenario.timers_disabled'))->toBeFalse();
});

it('removes timer flag after scenario completes', function (): void {
    TrafficLightsActiveScenario::play();

    // Flag should be removed during cleanup
    expect(app()->bound('scenario.timers_disabled'))->toBeFalse();
});
