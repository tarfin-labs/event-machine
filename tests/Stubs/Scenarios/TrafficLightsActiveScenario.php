<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Scenarios;

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

class TrafficLightsActiveScenario extends MachineScenario
{
    protected function machine(): string
    {
        return TrafficLightsMachine::class;
    }

    protected function description(): string
    {
        return 'Traffic lights in active state with count incremented';
    }

    protected function defaults(): array
    {
        return [
            'increment_count' => 3,
        ];
    }

    protected function steps(): array
    {
        $steps = [];

        for ($i = 0; $i < $this->param('increment_count'); $i++) {
            $steps[] = $this->send('INCREASE');
        }

        return $steps;
    }
}
