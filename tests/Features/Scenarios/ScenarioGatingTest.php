<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Exceptions\ScenariosDisabledException;
use Tarfinlabs\EventMachine\Tests\Stubs\Scenarios\TrafficLightsActiveScenario;

it('throws ScenariosDisabledException when scenarios are disabled', function (): void {
    config(['machine.scenarios.enabled' => false]);

    TrafficLightsActiveScenario::play();
})->throws(ScenariosDisabledException::class);

it('throws when scenarios config is not set', function (): void {
    config(['machine.scenarios.enabled' => null]);

    TrafficLightsActiveScenario::play();
})->throws(ScenariosDisabledException::class);

it('succeeds when scenarios are enabled', function (): void {
    config(['machine.scenarios.enabled' => true]);

    $result = TrafficLightsActiveScenario::play();

    expect($result->currentState)->toBe('active');
});
