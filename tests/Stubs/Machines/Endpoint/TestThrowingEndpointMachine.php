<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class TestThrowingEndpointMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'throwing_endpoint',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'START' => [
                                'target'  => 'started',
                                'actions' => 'throwAction',
                            ],
                        ],
                    ],
                    'started' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'START' => TestStartEvent::class,
                ],
                'actions' => [
                    'throwAction' => TestThrowingAction::class,
                ],
            ],
            endpoints: [
                'START' => [
                    'action' => TestEndpointAction::class,
                ],
            ],
        );
    }
}
