<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios;

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioForwardChildMachine;

/**
 * Child scenario that pauses at awaiting_input.
 * plan: processing → @done → awaiting_input (interactive, pause here)
 * continuation: finalizing → @done (when QA sends CONFIRM via forward endpoint).
 */
class ForwardChildPauseScenario extends MachineScenario
{
    protected string $machine     = ScenarioForwardChildMachine::class;
    protected string $source      = 'idle';
    protected string $event       = MachineScenario::START;
    protected string $target      = 'awaiting_input';
    protected string $description = 'Child pauses at awaiting_input for forward endpoint testing';

    protected function plan(): array
    {
        return [
            'processing' => '@done',
        ];
    }

    protected function continuation(): array
    {
        return [
            'finalizing' => '@done',
        ];
    }
}
