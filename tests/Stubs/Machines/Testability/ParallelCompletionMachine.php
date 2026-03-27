<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Parallel-region stub for testing assertAllRegionsCompleted().
 *
 * Two regions: payment and inventory.
 * - payment: pending -> paid (final) | payment_failed (final)
 * - inventory: checking -> reserved (final)
 *
 * @done -> fulfilled, @fail -> failed.
 *
 * Events:
 *   PAYMENT_SUCCESS   -> payment reaches "paid" (final)
 *   PAYMENT_FAIL      -> payment reaches "payment_failed" (final)
 *   INVENTORY_RESERVE -> inventory reaches "reserved" (final)
 */
class ParallelCompletionMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'parallel_completion',
                'initial' => 'processing',
                'context' => [
                    'paymentStatus'   => null,
                    'inventoryStatus' => null,
                ],
                'states' => [
                    'processing' => [
                        'type'   => 'parallel',
                        '@done'  => 'fulfilled',
                        '@fail'  => 'failed',
                        'states' => [
                            'payment' => [
                                'initial' => 'pending',
                                'states'  => [
                                    'pending' => [
                                        'on' => [
                                            'PAYMENT_SUCCESS' => 'paid',
                                            'PAYMENT_FAIL'    => 'payment_failed',
                                        ],
                                    ],
                                    'paid'           => ['type' => 'final'],
                                    'payment_failed' => ['type' => 'final'],
                                ],
                            ],
                            'inventory' => [
                                'initial' => 'checking',
                                'states'  => [
                                    'checking' => [
                                        'on' => [
                                            'INVENTORY_RESERVE' => 'reserved',
                                        ],
                                    ],
                                    'reserved' => ['type' => 'final'],
                                ],
                            ],
                        ],
                    ],
                    'fulfilled' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
        );
    }
}
