<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios;

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Guards\IsEligibleGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Events\RejectEvent;

/**
 * Failure path: idle → routing → processing(@fail) → failed.
 */
class FailurePathScenario extends MachineScenario
{
    protected string $machine     = ScenarioTestMachine::class;
    protected string $source      = 'idle';
    protected string $event       = MachineScenario::START;
    protected string $target      = 'failed';
    protected string $description = 'Failure path through job fail';

    protected function plan(): array
    {
        return [
            'routing' => [
                IsEligibleGuard::class => true,
            ],
            'processing' => '@fail',
        ];
    }
}
