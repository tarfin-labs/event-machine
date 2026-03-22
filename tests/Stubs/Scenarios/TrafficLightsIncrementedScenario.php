<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Scenarios;

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

/**
 * Extends TrafficLightsActiveScenario and adds more increments.
 * Used to test scenario composition via parent().
 */
class TrafficLightsIncrementedScenario extends MachineScenario
{
    protected function machine(): string
    {
        return TrafficLightsMachine::class;
    }

    protected function description(): string
    {
        return 'Traffic lights incremented further (extends active scenario)';
    }

    protected function parent(): string
    {
        return TrafficLightsActiveScenario::class;
    }

    protected function defaults(): array
    {
        return [
            'extra_increments' => 2,
        ];
    }

    protected function steps(): array
    {
        $steps = [];

        for ($i = 0; $i < $this->param('extra_increments'); $i++) {
            $steps[] = $this->send('INCREASE');
        }

        return $steps;
    }
}
