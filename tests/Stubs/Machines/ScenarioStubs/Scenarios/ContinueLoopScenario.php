<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios;

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Events\ApproveEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;

/**
 * Tests @continue with payload format: reviewing → APPROVE → approved.
 * Source is reviewing (machine already at reviewing state).
 */
class ContinueLoopScenario extends MachineScenario
{
    protected string $machine     = ScenarioTestMachine::class;
    protected string $source      = 'reviewing';
    protected string $event       = ApproveEvent::class;
    protected string $target      = 'approved';
    protected string $description = 'Continue loop from reviewing to approved';

    protected function plan(): array
    {
        return [];
    }
}
