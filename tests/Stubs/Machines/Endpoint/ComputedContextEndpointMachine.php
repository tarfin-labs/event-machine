<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\ComputedTestContext;

class ComputedContextEndpointMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'computed_endpoint',
                'initial' => 'idle',
                'context' => ComputedTestContext::class,
                'states'  => [
                    'idle' => [
                        'on' => [
                            'START' => [
                                'target'  => 'active',
                                'actions' => 'incrementAction',
                            ],
                        ],
                    ],
                    'active' => [
                        'on' => [
                            'COMPLETE' => 'done',
                        ],
                    ],
                    'done' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'START'    => TestStartEvent::class,
                    'COMPLETE' => TestCompleteEvent::class,
                ],
                'actions' => [
                    'incrementAction' => function (ComputedTestContext $context): void {
                        $context->count = $context->count + 1;
                    },
                ],
            ],
            endpoints: [
                'START',
                'COMPLETE' => [
                    'contextKeys' => ['count', 'is_count_even'],
                ],
            ],
        );
    }
}
