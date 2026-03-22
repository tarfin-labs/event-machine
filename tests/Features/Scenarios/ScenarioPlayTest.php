<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Scenarios\ScenarioResult;
use Tarfinlabs\EventMachine\Tests\Stubs\Scenarios\TrafficLightsActiveScenario;

beforeEach(function (): void {
    config(['machine.scenarios.enabled' => true]);
});

it('plays a basic scenario and returns ScenarioResult', function (): void {
    $result = TrafficLightsActiveScenario::play();

    expect($result)
        ->toBeInstanceOf(ScenarioResult::class)
        ->machineId->not->toBeEmpty()
        ->rootEventId->not->toBeEmpty()
        ->currentState->toBe('active')
        ->stepsExecuted->toBe(3)
        ->duration->toBeGreaterThan(0);
});

it('accepts parameter overrides', function (): void {
    $result = TrafficLightsActiveScenario::play(['increment_count' => 5]);

    expect($result->stepsExecuted)->toBe(5);
});

it('uses default parameters when no overrides provided', function (): void {
    $result = TrafficLightsActiveScenario::play();

    // Default increment_count is 3
    expect($result->stepsExecuted)->toBe(3);
});
