<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Scenarios;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Simple 3-state machine for mid-flight scenario testing.
 *
 * idle → ACTIVATE → active → FINISH → done (final)
 */
class MidFlightMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'mid_flight',
                'initial' => 'idle',
                'context' => [
                    'activatedAt' => null,
                    'finishedAt'  => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['ACTIVATE' => 'active'],
                    ],
                    'active' => [
                        'on' => ['FINISH' => 'done'],
                    ],
                    'done' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'ACTIVATE' => MidFlightActivateEvent::class,
                    'FINISH'   => MidFlightFinishEvent::class,
                ],
            ],
            endpoints: [
                'ACTIVATE',
                'FINISH',
            ],
        );
    }
}
