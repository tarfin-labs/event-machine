<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Support\Timer;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Machine with @every timer with max and then.
 * RETRY fires every 6 hours, max 3 times, then MAX_RETRIES.
 */
class EveryWithMaxMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'every_max',
                'initial' => 'retrying',
                'context' => [
                    'retry_count' => 0,
                ],
                'states' => [
                    'retrying' => [
                        'on' => [
                            'RETRY'           => ['actions' => 'incrementRetryAction', 'every' => Timer::hours(6), 'max' => 3, 'then' => 'MAX_RETRIES'],
                            'MAX_RETRIES'     => 'failed',
                            'PAYMENT_SUCCESS' => 'paid',
                        ],
                    ],
                    'failed' => ['type' => 'final'],
                    'paid'   => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'incrementRetryAction' => function (ContextManager $ctx): void {
                        $ctx->set('retry_count', $ctx->get('retry_count') + 1);
                    },
                ],
            ],
        );
    }
}
