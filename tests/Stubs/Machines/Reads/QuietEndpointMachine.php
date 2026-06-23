<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Reads;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Endpoint machine whose event endpoint sets `available_events => false`, to verify the
 * shared buildResponse() fix omits the key on the endpoint (command) side too.
 */
class QuietEndpointMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'quiet_endpoint',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => ['GO' => 'done'],
                    ],
                    'done' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'GO' => GoEvent::class,
                ],
            ],
            endpoints: [
                'GO' => ['available_events' => false],
            ],
        );
    }
}
