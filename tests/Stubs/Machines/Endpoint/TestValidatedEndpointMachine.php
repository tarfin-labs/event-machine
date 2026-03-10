<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class TestValidatedEndpointMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'validated_endpoint',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'START' => [
                                'target' => 'started',
                                'guards' => TestAlwaysFailValidationGuard::class,
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
            ],
            endpoints: [
                'START' => null,
            ],
        );
    }
}
