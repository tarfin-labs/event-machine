<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios;

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Guards\IsValidGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestChildMachine;

/**
 * @start scenario for ScenarioTestChildMachine.
 * idle (@always) → verifying (@done) → verified.
 */
class StartScenario extends MachineScenario
{
    protected string $machine     = ScenarioTestChildMachine::class;
    protected string $source      = 'idle';
    protected string $event       = MachineScenario::START;
    protected string $target      = 'verified';
    protected string $description = 'Start child machine to verified state';

    protected function plan(): array
    {
        return [
            'verifying' => [
                'outcome'           => '@done',
                IsValidGuard::class => true,
            ],
        ];
    }
}
