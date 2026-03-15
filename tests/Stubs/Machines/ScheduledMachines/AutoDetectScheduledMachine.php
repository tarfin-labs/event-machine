<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScheduledMachines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Machine with null resolver schedule on a state-level event.
 *
 * CHECK_EXPIRY is only handled in 'active' state (not root-level),
 * so auto-detect should only target instances in 'active'.
 */
class AutoDetectScheduledMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'auto_detect_scheduled',
                'initial' => 'active',
                'states'  => [
                    'active' => [
                        'on' => [
                            'CHECK_EXPIRY' => 'expired',
                            'SUSPEND'      => 'suspended',
                        ],
                    ],
                    'suspended' => [
                        'on' => [
                            'REACTIVATE' => 'active',
                        ],
                    ],
                    'expired' => [
                        'type' => 'final',
                    ],
                ],
            ],
            schedules: [
                'CHECK_EXPIRY' => null,
            ],
        );
    }
}
