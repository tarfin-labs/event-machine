<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScheduledMachines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Machine with compound (hierarchical) state where the parent handles the scheduled event.
 *
 * 'payment' parent handles CHECK_EXPIRY via event bubbling.
 * machine_current_states records leaf states: payment.pending, payment.processing.
 * Auto-detect must expand 'payment' to include its children.
 */
class CompoundScheduledMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'compound_scheduled',
                'initial' => 'payment',
                'states'  => [
                    'payment' => [
                        'initial' => 'pending',
                        'on'      => [
                            'CHECK_EXPIRY' => 'expired',
                        ],
                        'states' => [
                            'pending' => [
                                'on' => [
                                    'PROCESS' => 'processing',
                                ],
                            ],
                            'processing' => [],
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
