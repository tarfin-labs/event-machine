<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Fixtures\ScenarioDrift;

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;

/**
 * The ordinary shape: activated once, no continuation, finishing at a non-final state.
 *
 * This is the common path and it goes through ScenarioPlayer::execute(), not
 * executeContinuation(). execute() used to re-persist the scenario unconditionally on its way
 * out, so `scenario_class` stayed set forever for almost every scenario anyone writes.
 */
class PlainNoContinuationScenario extends MachineScenario
{
    protected string $machine     = PersistingScenarioMachine::class;
    protected string $source      = 'idle';
    protected string $event       = 'GO';
    protected string $target      = 'midway';
    protected string $description = 'Runs to a non-final target and is then done';

    protected function plan(): array
    {
        return [];
    }
}
