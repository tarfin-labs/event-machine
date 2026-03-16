<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Machine with a throwing action and a recovering endpoint action.
 * onException returns a JsonResponse instead of null.
 */
class TestRecoveringEndpointMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'recovering_endpoint',
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
                    'action' => TestRecoveringEndpointAction::class,
                ],
            ],
        );
    }
}
