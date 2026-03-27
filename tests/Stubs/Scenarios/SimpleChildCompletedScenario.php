<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Scenarios;

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\SimpleChildMachine;

/**
 * Completes the SimpleChildMachine: idle → done.
 */
class SimpleChildCompletedScenario extends MachineScenario
{
    protected function machine(): string
    {
        return SimpleChildMachine::class;
    }

    protected function description(): string
    {
        return 'Complete the simple child machine';
    }

    protected function steps(): array
    {
        return [
            $this->send('COMPLETE'),
        ];
    }
}
