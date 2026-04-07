<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class PersistenceFidelityAlwaysChainMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'pf_always_chain',
                'initial' => 'idle',
                'context' => [
                    'capturedTriggeringType' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => [
                            'SUBMIT' => 'routing',
                        ],
                    ],
                    'routing' => [
                        'on' => [
                            '@always' => [
                                'target'  => 'processing',
                                'actions' => 'captureAction',
                            ],
                        ],
                    ],
                    'processing' => [
                        'on' => [
                            '@always' => 'done',
                        ],
                    ],
                    'done' => [
                        'type' => 'final',
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'captureAction' => function (ContextManager $context, EventBehavior $event): void {
                        $context->set('capturedTriggeringType', $event->type);
                    },
                ],
            ],
        );
    }
}
