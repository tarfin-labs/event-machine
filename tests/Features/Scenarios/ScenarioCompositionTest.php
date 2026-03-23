<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Exceptions\ScenarioConfigurationException;
use Tarfinlabs\EventMachine\Tests\Stubs\Scenarios\TrafficLightsActiveScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Scenarios\TrafficLightsIncrementedScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

beforeEach(function (): void {
    config(['machine.scenarios.enabled' => true]);
});

it('plays parent scenario before child steps and produces correct context', function (): void {
    // Parent does 3 increments, child does 2 more = 5 total
    $result = TrafficLightsIncrementedScenario::play();

    expect($result->currentState)->toBe('active');

    // Verify context proves parent steps ran: 3 (parent) + 2 (child) = 5
    $machine = TrafficLightsMachine::create(state: $result->rootEventId);
    expect($machine->state->context->count)->toBe(5);
});

it('merges defaults from parent and child and applies overrides', function (): void {
    // Override parent default: increment_count=1 (instead of 3)
    // Child default: extra_increments=2
    // Total: 1 + 2 = 3
    $result = TrafficLightsIncrementedScenario::play(['increment_count' => 1]);

    $machine = TrafficLightsMachine::create(state: $result->rootEventId);
    expect($machine->state->context->count)->toBe(3);
});

it('throws on machine mismatch in parent chain', function (): void {
    $mismatchedClass = new class() extends MachineScenario {
        protected function machine(): string
        {
            return 'App\Machines\DifferentMachine';
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
