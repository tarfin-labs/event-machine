<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Scenarios\StubContractAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Scenarios\StubContractMachine;

beforeEach(function (): void {
    config(['machine.scenarios.enabled' => true]);
    StubContractAction::reset();
});

it('uses applyStub() when action implements ScenarioStubContract', function (): void {
    $scenarioClass = new class() extends MachineScenario {
        protected function machine(): string
        {
            return StubContractMachine::class;
        }

        protected function description(): string
        {
            return 'Test ScenarioStubContract';
        }

        protected function arrange(): array
        {
            return [
                StubContractAction::class => ['score' => 42],
            ];
        }

        protected function steps(): array
        {
            return [
                $this->send('SCORE'),
            ];
        }
    };

    $result = $scenarioClass::play();

    // applyStub multiplies score by 10: 42 * 10 = 420
    expect(StubContractAction::$applyStubWasCalled)->toBeTrue();
    expect(StubContractAction::$invokeWasCalled)->toBeFalse();

    $machine = StubContractMachine::create(state: $result->rootEventId);
    expect($machine->state->context->score)->toBe(420);
});
