<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios;

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Events\ApproveEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Guards\IsEligibleGuard;

/**
 * Outcome with output: processing has ['outcome' => '@done', 'output' => [...]].
 */
class OutcomeWithOutputScenario extends MachineScenario
{
    protected string $machine     = ScenarioTestMachine::class;
    protected string $source      = 'idle';
    protected string $event       = MachineScenario::START;
    protected string $target      = 'approved';
    protected string $description = 'Outcome with output data';

    protected function plan(): array
    {
        return [
            'routing' => [
                IsEligibleGuard::class => true,
            ],
            'processing' => [
                'outcome' => '@done',
                'output'  => ['processedBy' => 'scenario', 'amount' => 100],
            ],
            'reviewing' => [
                '@continue' => ApproveEvent::class,
            ],
        ];
    }
}
