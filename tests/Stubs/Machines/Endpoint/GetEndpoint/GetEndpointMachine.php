<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\GetEndpoint;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class GetEndpointMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'get_endpoint',
                'initial'        => 'idle',
                'should_persist' => true,
                'context'        => [
                    'dealerCode'  => null,
                    'plateNumber' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => [
                            'STATUS_REQUESTED' => [
                                'target'      => 'done',
                                'calculators' => StoreStatusCalculator::class,
                            ],
                        ],
                    ],
                    'done' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'STATUS_REQUESTED' => StatusRequestedEvent::class,
                ],
            ],
            endpoints: [
                'STATUS_REQUESTED' => [
                    'uri'    => '/status',
                    'method' => 'GET',
                ],
            ],
        );
    }
}
