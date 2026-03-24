<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\SimpleEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;

class TestMiddlewareEndpointMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'mw_test',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'SUBMIT' => 'done',
                        ],
                    ],
                    'done' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
                'events'  => [
                    'SUBMIT' => SimpleEvent::class,
                ],
            ],
            endpoints: [
                'SUBMIT' => [
                    'middleware' => ['verified', 'can:submit'],
                ],
            ],
        );
    }
}
