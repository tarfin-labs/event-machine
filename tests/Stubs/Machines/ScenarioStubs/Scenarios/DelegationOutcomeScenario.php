<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios;

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Guards\IsEligibleGuard;

/**
 * Delegation outcome scenario: idle → routing → processing(@done) → reviewing → DELEGATE → delegating(@done) → delegation_complete.
 */
class DelegationOutcomeScenario extends MachineScenario
{
    protected string $machine     = ScenarioTestMachine::class;
    protected string $source      = 'idle';
    protected string $event       = MachineScenario::START;
    protected string $target      = 'delegation_complete';
    protected string $description = 'Delegation path through child machine';

    protected function plan(): array
    {
        return [
            'routing' => [
                IsEligibleGuard::class => true,
            ],
            'processing' => '@done',
            'reviewing'  => [
                '@continue' => 'DELEGATE',
            ],
            'delegating' => '@done',
        ];
    }
}
