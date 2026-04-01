<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios;

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Events\ApproveEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Guards\IsEligibleGuard;

/**
 * Scenario with params() — both plain and rich definitions.
 */
class ParameterizedScenario extends MachineScenario
{
    protected string $machine     = ScenarioTestMachine::class;
    protected string $source      = 'idle';
    protected string $event       = MachineScenario::START;
    protected string $target      = 'approved';
    protected string $description = 'Parameterized scenario with amount filter';

    protected function params(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'],
            'note'   => [
                'type'  => 'string',
                'rules' => ['nullable', 'string', 'max:255'],
                'label' => 'Optional note',
            ],
        ];
    }

    protected function plan(): array
    {
        return [
            'routing' => [
                IsEligibleGuard::class => true,
            ],
            'processing' => '@done',
            'reviewing'  => [
                '@continue' => ApproveEvent::class,
            ],
        ];
    }
}
