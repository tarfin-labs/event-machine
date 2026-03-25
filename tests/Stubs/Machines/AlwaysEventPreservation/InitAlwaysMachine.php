<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysEventPreservation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

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
                    'initEventType' => 'not_called',
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
                'actions' => [
                    'captureInitEventAction' => CaptureInitEventAction::class,
                ],
            ],
        );
    }
}
