<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Scenarios\ChildScenarioStep;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

beforeEach(function (): void {
    config(['machine.scenarios.enabled' => true]);
});

it('creates ChildScenarioStep with fluent API', function (): void {
    $step = new ChildScenarioStep(TrafficLightsMachine::class);

    expect($step->machineClass)->toBe(TrafficLightsMachine::class);
    expect($step->getScenarioClass())->toBeNull();
    expect($step->getParams())->toBe([]);

    $step = $step->scenario('SomeScenario')->with(['key' => 'value']);

    expect($step->getScenarioClass())->toBe('SomeScenario');
    expect($step->getParams())->toBe(['key' => 'value']);
});

it('skips child scenario step when no scenario class is set', function (): void {
    $scenarioClass = new class() extends MachineScenario {
        protected function machine(): string
        {
            return TrafficLightsMachine::class;
        }

        protected function description(): string
        {
            return 'Test with child step without scenario';
        }

        protected function steps(): array
        {
            return [
                $this->send('INCREASE'),
                // Child step without scenario() — should be skipped
                $this->child(TrafficLightsMachine::class),
                $this->send('INCREASE'),
            ];
        }
    };

    $result = $scenarioClass::play();

    // 2 sends + 1 child step (skipped but counted) = 3 steps
    expect($result->stepsExecuted)->toBe(3);
});
