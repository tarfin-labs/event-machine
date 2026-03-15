<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Support\Timer;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Machine with @after timer on a transition.
 * ORDER_EXPIRED auto-fires after 7 days.
 */
class AfterTimerMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'after_timer',
                'initial' => 'awaiting_payment',
                'context' => [
                    'order_id' => null,
                ],
                'states' => [
                    'awaiting_payment' => [
                        'on' => [
                            'PAY'           => 'processing',
                            'ORDER_EXPIRED' => ['target' => 'cancelled', 'after' => Timer::days(7)],
                        ],
                    ],
                    'processing' => [
                        'on' => ['COMPLETE' => 'completed'],
                    ],
                    'cancelled' => ['type' => 'final'],
                    'completed' => ['type' => 'final'],
                ],
            ],
        );
    }
}
