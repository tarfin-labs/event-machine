<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\GetEndpoint;

use Tarfinlabs\EventMachine\Actor\Machine;
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
                    'child_param' => null,
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
                    'storeChildParamCalculator' => function (\Tarfinlabs\EventMachine\ContextManager $ctx, \Tarfinlabs\EventMachine\Behavior\EventBehavior $event): void {
                        $ctx->set('child_param', $event->payload['child_param'] ?? null);
                    },
                ],
            ],
        );
    }
}
