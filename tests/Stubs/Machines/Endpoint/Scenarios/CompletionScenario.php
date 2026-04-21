<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\Scenarios;

use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestCompleteEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestEndpointMachine;

/**
 * Scenario targeting TestEndpointMachine: started → COMPLETE → completed.
 * Used in event mismatch endpoint tests.
 */
class CompletionScenario extends MachineScenario
{
    protected string $machine     = TestEndpointMachine::class;
    protected string $source      = 'started';
    protected string $event       = TestCompleteEvent::class;
    protected string $target      = 'completed';
    protected string $description = 'Complete from started state';

    protected function plan(): array
    {
        return [];
    }
}
