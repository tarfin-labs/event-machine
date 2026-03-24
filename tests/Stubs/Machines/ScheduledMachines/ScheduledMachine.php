<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScheduledMachines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;

/**
 * Machine with schedule definitions for testing.
 *
 * - CHECK_EXPIRY: class-based resolver
 * - DAILY_REPORT: null resolver (auto-detect)
 */
class ScheduledMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'scheduled_machine',
                'initial' => 'active',
                'on'      => [
                    'DAILY_REPORT' => 'active',
                ],
                'states' => [
                    'active' => [
                        'on' => [
                            'CHECK_EXPIRY' => 'expired',
                        ],
                    ],
                    'expired' => [
                        'type' => 'final',
                    ],
                ],
            ],
            schedules: [
                'CHECK_EXPIRY' => ExpiredApplicationsResolver::class,
                'DAILY_REPORT' => null,
            ],
            behavior: [
                'context' => GenericContext::class,
            ]
        );
    }
}
