<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Exceptions\ScenarioFailedException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

beforeEach(function (): void {
    config(['machine.scenarios.enabled' => true]);
});

it('throws ScenarioFailedException on invalid event', function (): void {
    $scenarioClass = new class() extends MachineScenario {
        protected function machine(): string
        {
            return TrafficLightsMachine::class;
        }

        protected function description(): string
        {
            return 'Test scenario with invalid event';
        }

        protected function steps(): array
        {
            return [
                $this->send('INCREASE'),
                $this->send('NONEXISTENT_EVENT'),
            ];
        }
    };

    config(['machine.scenarios.enabled' => true]);
    $scenarioClass::play();
})->throws(ScenarioFailedException::class);

it('includes step index and event type in failure exception', function (): void {
    $scenarioClass = new class() extends MachineScenario {
        protected function machine(): string
        {
            return TrafficLightsMachine::class;
        }

        protected function description(): string
        {
            return 'Test scenario for error context';
        }

        protected function steps(): array
        {
            return [
                $this->send('INCREASE'),
                $this->send('INVALID_EVENT_FOR_CONTEXT'),
            ];
        }
    };

    config(['machine.scenarios.enabled' => true]);

    try {
        $scenarioClass::play();
        $this->fail('Expected ScenarioFailedException');
    } catch (ScenarioFailedException $e) {
        expect($e->stepIndex)->toBe(1);
        expect($e->eventType)->toBe('INVALID_EVENT_FOR_CONTEXT');
    }
});
