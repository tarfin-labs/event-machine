<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Support\Timer;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Machine with @every timer on a transition.
 * BILLING auto-fires every 30 days.
 */
class EveryTimerMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'every_timer',
                'initial' => 'active',
                'context' => [
                    'billingCount' => 0,
                ],
                'states' => [
                    'active' => [
                        'on' => [
                            'BILLING' => ['actions' => 'incrementBillingAction', 'every' => Timer::days(30)],
                            'CANCEL'  => 'cancelled',
                        ],
                    ],
                    'cancelled' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'incrementBillingAction' => function (ContextManager $ctx): void {
                        $ctx->set('billingCount', $ctx->get('billingCount') + 1);
                    },
                ],
            ],
        );
    }
}
