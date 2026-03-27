<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Scenarios;

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Scenarios\MidFlightMachine;

/**
 * Mid-flight scenario: expects machine at 'active', finishes it to 'done'.
 */
class MidFlightFinishScenario extends MachineScenario
{
    protected function machine(): string
    {
        return MidFlightMachine::class;
    }

    protected function description(): string
    {
        return 'Finish the machine from active state (mid-flight)';
    }

    protected function from(): ?string
    {
        return 'active';
    }

    protected function steps(): array
    {
        return [
            $this->send('FINISH'),
        ];
    }
}
