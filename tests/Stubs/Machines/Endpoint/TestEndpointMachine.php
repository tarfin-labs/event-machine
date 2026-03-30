<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class TestEndpointMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'test_endpoint',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'START' => 'started',
                        ],
                    ],
                    'started' => [
                        'on' => [
                            'COMPLETE' => 'completed',
                            'CANCEL'   => 'cancelled',
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                    'cancelled' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'START'    => TestStartEvent::class,
                    'COMPLETE' => TestCompleteEvent::class,
                    'CANCEL'   => TestCancelEvent::class,
                ],
                'outputs' => [
                    'testEndpointOutput' => TestEndpointOutput::class,
                ],
            ],
            endpoints: [
                'START' => [
                    'action' => TestEndpointAction::class,
                ],
                'COMPLETE' => [
                    'output' => 'testEndpointOutput',
                    'status' => 201,
                ],
                TestCancelEvent::class,
            ],
        );
    }
}
