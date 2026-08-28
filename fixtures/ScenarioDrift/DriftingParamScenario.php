<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Fixtures\ScenarioDrift;

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;

/**
 * A scenario carrying a param whose rule can stop holding without the machine changing.
 *
 * Validation belongs to the moment params are accepted. Re-running the rules on every later
 * restore is what turned a deleted row — or midnight, for a date rule — into an unloadable
 * machine.
 */
class DriftingParamScenario extends MachineScenario
{
    protected string $machine     = PersistingScenarioMachine::class;
    protected string $source      = 'idle';
    protected string $event       = 'GO';
    protected string $target      = 'done';
    protected string $description = 'Carries a param whose rule reads the world';

    public function params(): array
    {
        return [
            'pickedValue' => ['required', 'integer'],
        ];
    }

    protected function plan(): array
    {
        return [];
    }
}
