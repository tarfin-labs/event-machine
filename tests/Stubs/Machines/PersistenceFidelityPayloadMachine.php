<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class PersistenceFidelityPayloadMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'pf_payload',
                'initial' => 'idle',
                'context' => [
                    'receivedPayload' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => [
                            'SUBMIT' => [
                                'target'  => 'done',
                                'actions' => 'capturePayloadAction',
                            ],
                        ],
                    ],
                    'done' => [
                        'type' => 'final',
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'capturePayloadAction' => function (ContextManager $context, EventBehavior $event): void {
                        $context->set('receivedPayload', $event->payload);
                    },
                ],
            ],
        );
    }
}
