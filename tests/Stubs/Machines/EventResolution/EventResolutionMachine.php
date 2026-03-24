<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\EventResolution;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\MachineRegisteredEvent;

class EventResolutionMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'event_resolution',
                'initial' => 'idle',
                'context' => [
                    'amount' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => [
                            'TEST_EVENT'  => 'processing',
                            'OTHER_EVENT' => 'processing',
                        ],
                    ],
                    'processing' => [
                        'type' => 'final',
                    ],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
                'events'  => [
                    'TEST_EVENT' => MachineRegisteredEvent::class,
                ],
            ],
        );
    }
}
