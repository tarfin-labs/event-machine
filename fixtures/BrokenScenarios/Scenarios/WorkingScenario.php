<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Fixtures\BrokenScenarios\Scenarios;

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Fixtures\BrokenScenarios\BrokenScenarioMachine;

/**
 * The control. Without a scenario that does load, "0 validated" would be indistinguishable
 * from a discovery that found nothing at all.
 */
class WorkingScenario extends MachineScenario
{
    protected string $machine     = BrokenScenarioMachine::class;
    protected string $source      = 'idle';
    protected string $event       = 'GO';
    protected string $target      = 'done';
    protected string $description = 'The scenario that loads, so the count means something';

    protected function plan(): array
    {
        return [];
    }
}
