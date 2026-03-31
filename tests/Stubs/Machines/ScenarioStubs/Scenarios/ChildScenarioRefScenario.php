<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios;

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Guards\IsEligibleGuard;

/**
 * References a child scenario (StartScenario) for the delegating state.
 */
class ChildScenarioRefScenario extends MachineScenario
{
    protected string $machine     = ScenarioTestMachine::class;
    protected string $source      = 'idle';
    protected string $event       = MachineScenario::START;
    protected string $target      = 'delegation_complete';
    protected string $description = 'Delegation with child scenario reference';

    protected function plan(): array
    {
        return [
            'routing' => [
                IsEligibleGuard::class => true,
            ],
            'processing' => '@done',
            'reviewing' => [
                '@continue' => 'DELEGATE',
            ],
            'delegating' => StartScenario::class,
        ];
    }
}
