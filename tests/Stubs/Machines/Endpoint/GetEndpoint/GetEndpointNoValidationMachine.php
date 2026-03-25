<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\GetEndpoint;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class GetEndpointNoValidationMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'get_endpoint_no_validation',
                'initial' => 'idle',
                'context' => [
                    'ping_payload' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => [
                            'PING' => [
                                'target'      => 'done',
                                'calculators' => StorePingPayloadCalculator::class,
                            ],
                        ],
                    ],
                    'done' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'PING' => PingEvent::class,
                ],
            ],
            endpoints: [
                'PING' => [
                    'uri'    => '/ping',
                    'method' => 'GET',
                ],
            ],
        );
    }
}
