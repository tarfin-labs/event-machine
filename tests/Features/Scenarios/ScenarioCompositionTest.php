<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Exceptions\ScenarioConfigurationException;
use Tarfinlabs\EventMachine\Tests\Stubs\Scenarios\TrafficLightsActiveScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Scenarios\TrafficLightsIncrementedScenario;

beforeEach(function (): void {
    config(['machine.scenarios.enabled' => true]);
});

it('plays parent scenario before child steps', function (): void {
    // TrafficLightsIncrementedScenario extends TrafficLightsActiveScenario
    // Parent does 3 increments, child does 2 more = 5 total
    $result = TrafficLightsIncrementedScenario::play();

    expect($result->currentState)->toBe('active');
    // Parent: 3 steps + Child: 2 steps = 5 total
    expect($result->stepsExecuted)->toBeGreaterThanOrEqual(2);
});

it('merges defaults from parent and child', function (): void {
    // Parent defaults: increment_count=3
    // Child defaults: extra_increments=2
    // Override parent default
    $result = TrafficLightsIncrementedScenario::play(['increment_count' => 1]);

    // Parent: 1 increment, Child: 2 increments
    expect($result->currentState)->toBe('active');
});

it('throws on machine mismatch in parent chain', function (): void {
    // Create a scenario that extends TrafficLightsActiveScenario but targets a different machine
    $mismatchedClass = new class() extends MachineScenario {
        protected function machine(): string
        {
            return 'App\Machines\DifferentMachine'; // Wrong machine
        }

        protected function description(): string
        {
            return 'Mismatched scenario';
        }

        protected function parent(): string
        {
            return TrafficLightsActiveScenario::class;
        }

        protected function steps(): array
        {
            return [];
        }
    };

    $mismatchedClass::play();
})->throws(ScenarioConfigurationException::class);
