<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\GetEndpoint;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class GetForwardParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'get_forward_parent',
                'initial'        => 'idle',
                'should_persist' => true,
                'context'        => [],
                'states'         => [
                    'idle' => [
                        'on' => ['START' => 'delegating'],
                    ],
                    'delegating' => [
                        'machine' => GetForwardChildMachine::class,
                        'forward' => [
                            'CHILD_STATUS' => [
                                'child_event' => 'CHILD_STATUS',
                                'method'      => 'GET',
                                'uri'         => '/child-status',
                            ],
                        ],
                        '@done' => 'completed',
                        '@fail' => 'failed',
                    ],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'START' => GetForwardStartEvent::class,
                ],
            ],
            endpoints: [
                'START',
            ],
        );
    }
}
