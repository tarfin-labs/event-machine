<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Scenarios;

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Scenarios\MidFlightMachine;

/**
 * Brings the MidFlightMachine from idle to active.
 * Used as a setup step — creates a new machine.
 */
class MidFlightToActiveScenario extends MachineScenario
{
    protected function machine(): string
    {
        return MidFlightMachine::class;
    }

    protected function description(): string
    {
        return 'Activate the machine (idle → active)';
    }

    protected function steps(): array
    {
        return [
            $this->send('ACTIVATE'),
        ];
    }
}
