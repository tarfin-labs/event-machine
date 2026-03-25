<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Guards\CanRetryGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\SendAlertAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\IncrementRetryAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\SetPaymentSuccessAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\SetInventorySuccessAction;

/**
 * A parallel state machine with conditional @fail guards.
 *
 * Two regions. When a region reaches failure state, @fail fires.
 * If retry_count < 3 → retrying, otherwise → failed.
 */
class ConditionalOnFailMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'conditional_on_fail',
                'initial' => 'processing',
                'context' => [
                    'inventoryResult' => null,
                    'paymentResult'   => null,
                    'retryCount'      => 0,
                    'alertSent'       => false,
                ],
                'states' => [
                    'processing' => [
                        'type'  => 'parallel',
                        '@done' => 'completed',
                        '@fail' => [
                            ['target' => 'retrying', 'guards' => CanRetryGuard::class, 'actions' => IncrementRetryAction::class],
                            ['target' => 'failed',   'actions' => SendAlertAction::class],
                        ],
                        'states' => [
                            'inventory' => [
                                'initial' => 'checking',
                                'states'  => [
                                    'checking' => [
                                        'entry' => SetInventorySuccessAction::class,
                                        'on'    => [
                                            'INVENTORY_CHECKED' => 'done',
                                        ],
                                    ],
                                    'done' => ['type' => 'final'],
                                ],
                            ],
                            'payment' => [
                                'initial' => 'validating',
                                'states'  => [
                                    'validating' => [
                                        'entry' => SetPaymentSuccessAction::class,
                                        'on'    => [
                                            'PAYMENT_VALIDATED' => 'done',
                                            'PAYMENT_FAILED'    => 'error',
                                        ],
                                    ],
                                    'done'  => ['type' => 'final'],
                                    'error' => ['type' => 'final'],
                                ],
                            ],
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                    'retrying'  => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
        );
    }
}
