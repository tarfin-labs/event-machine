<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Scenarios;

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\SimpleChildMachine;

/**
 * Plays the full async parent flow: idle → processing → child completed → completed.
 * Demonstrates child scenario integration with real delegation.
 */
class AsyncParentCompletedScenario extends MachineScenario
{
    protected function machine(): string
    {
        return AsyncParentMachine::class;
    }

    protected function description(): string
    {
        return 'Async parent with child completed';
    }

    protected function defaults(): array
    {
        return [
            'orderId' => 'ORD-001',
        ];
    }

    protected function steps(): array
    {
        return [
            $this->send('START', ['orderId' => $this->param('orderId')]),

            // Child machine was spawned by START → processing (Bus::fake intercepts ChildMachineJob)
            // Play child scenario to complete it
            $this->child(SimpleChildMachine::class)
                ->scenario(SimpleChildCompletedScenario::class),
        ];
    }
}
