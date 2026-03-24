<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\SimpleEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;

class TestNoEndpointMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'no_endpoints',
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
                'context' => GenericContext::class,
                'events'  => [
                    'GO' => SimpleEvent::class,
                ],
            ],
        );
    }
}
