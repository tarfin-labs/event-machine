<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysEventPreservation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;

/**
 * Machine whose initial state has @always transition.
 *
 * Flow: routing(@always) → active
 *
 * No triggering event exists — behaviors should receive null event.
 */
class InitAlwaysMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'init_always',
                'initial' => 'routing',
                'context' => [
                    'init_event_type' => 'not_called',
                ],
                'states' => [
                    'routing' => [
                        'on' => [
                            '@always' => [
                                'target'  => 'active',
                                'actions' => 'captureInitEventAction',
                            ],
                        ],
                    ],
                    'active' => [],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
                'actions' => [
                    'captureInitEventAction' => CaptureInitEventAction::class,
                ],
            ],
        );
    }
}
