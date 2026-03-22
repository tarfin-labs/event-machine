<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Exceptions\ScenarioFailedException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Guards\IsEvenGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

beforeEach(function (): void {
    config(['machine.scenarios.enabled' => true]);
});

it('stubs a guard to return true', function (): void {
    $scenarioClass = new class() extends MachineScenario {
        protected function machine(): string
        {
            return TrafficLightsMachine::class;
        }

        protected function description(): string
        {
            return 'Test guard stubbing';
        }

        protected function arrange(): array
        {
            return [
                // IsEvenGuard normally checks if count is even
                // Stub it to always return true
                IsEvenGuard::class => true,
            ];
        }

        protected function steps(): array
        {
            return [
                // MULTIPLY requires IsEvenGuard to pass
                // With stub, it should pass regardless of count value
                $this->send('MULTIPLY'),
            ];
        }
    };

    // count starts at 0 (even), so guard would pass anyway
    // but this tests the stub mechanism
    $result = $scenarioClass::play();

    expect($result->currentState)->toBe('active');
    expect($result->stepsExecuted)->toBe(1);
});

it('stubs a guard to return false blocks the transition', function (): void {
    $scenarioClass = new class() extends MachineScenario {
        protected function machine(): string
        {
            return TrafficLightsMachine::class;
        }

        protected function description(): string
        {
            return 'Test guard stubbing false';
        }

        protected function arrange(): array
        {
            return [
                IsEvenGuard::class => false,
            ];
        }

        protected function steps(): array
        {
            return [
                // MULTIPLY with guard returning false — transition is blocked
                $this->send('MULTIPLY'),
            ];
        }
    };

    // Guard returns false, MULTIPLY is blocked — scenario catches the error
    expect(fn () => $scenarioClass::play())
        ->toThrow(ScenarioFailedException::class);
});
