<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * An order workflow machine with three parallel regions:
 * - documents: pending -> complete
 * - delivery: preparing -> shipped -> delivered
 * - invoice: draft -> sent -> paid
 *
 * When all regions reach their final states, the order is fulfilled.
 */
class OrderWorkflowMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'orderWorkflow',
                'initial' => 'processing',
                'context' => [
                    'orderId'     => null,
                    'totalAmount' => 0,
                ],
                'states' => [
                    'processing' => [
                        'type'   => 'parallel',
                        'onDone' => 'fulfilled',
                        'states' => [
                            'documents' => [
                                'initial' => 'pending',
                                'states'  => [
                                    'pending' => [
                                        'on' => [
                                            'DOCUMENTS_READY' => 'complete',
                                        ],
                                    ],
                                    'complete' => [
                                        'type' => 'final',
                                    ],
                                ],
                            ],
                            'delivery' => [
                                'initial' => 'preparing',
                                'states'  => [
                                    'preparing' => [
                                        'on' => [
                                            'SHIP' => 'shipped',
                                        ],
                                    ],
                                    'shipped' => [
                                        'on' => [
                                            'DELIVER' => 'delivered',
                                        ],
                                    ],
                                    'delivered' => [
                                        'type' => 'final',
                                    ],
                                ],
                            ],
                            'invoice' => [
                                'initial' => 'draft',
                                'states'  => [
                                    'draft' => [
                                        'on' => [
                                            'SEND_INVOICE' => 'sent',
                                        ],
                                    ],
                                    'sent' => [
                                        'on' => [
                                            'PAYMENT_RECEIVED' => 'paid',
                                        ],
                                    ],
                                    'paid' => [
                                        'type' => 'final',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'fulfilled' => [
                        'type' => 'final',
                    ],
                ],
            ],
        );
    }
}
