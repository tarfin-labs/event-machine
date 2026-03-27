<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\GetEndpoint;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class GetForwardChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'get_forward_child',
                'initial' => 'idle',
                'context' => [
                    'childParam' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => [
                            'CHILD_STATUS' => [
                                'target'      => 'done',
                                'calculators' => 'storeChildParamCalculator',
                            ],
                        ],
                    ],
                    'done' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'CHILD_STATUS' => ChildStatusEvent::class,
                ],
                'calculators' => [
                    'storeChildParamCalculator' => function (ContextManager $ctx, EventBehavior $event): void {
                        $ctx->set('childParam', $event->payload['child_param'] ?? null);
                    },
                ],
            ],
        );
    }
}
