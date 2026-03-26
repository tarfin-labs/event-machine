<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Reproduces CarSalesMachine's retailer region pattern:
 * awaiting_input → (event) → calculating → (@always + action writes context) → awaiting_options
 *
 * Inside a parallel state so the @always chain goes through transitionParallelState().
 * Tests that context written by an @always action is persisted and available after restore.
 */
class AlwaysInParallelRegionMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'always_in_parallel_region',
                'initial'        => 'idle',
                'should_persist' => true,
                'context'        => [
                    'inputData'      => null,
                    'calculatedData' => null,
                    'selectedOption' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'processing'],
                    ],
                    'processing' => [
                        'type'   => 'parallel',
                        '@done'  => 'completed',
                        'states' => [
                            'retailer' => [
                                'initial' => 'awaiting_input',
                                'states'  => [
                                    'awaiting_input' => [
                                        'on' => [
                                            'DATA_SUBMITTED' => [
                                                'target'  => 'calculating',
                                                'actions' => 'storeInputAction',
                                            ],
                                        ],
                                    ],
                                    'calculating' => [
                                        'on' => [
                                            '@always' => [
                                                'target'  => 'awaiting_options',
                                                'actions' => 'calculateAction',
                                            ],
                                        ],
                                    ],
                                    'awaiting_options' => [
                                        'on' => [
                                            'OPTIONS_SELECTED' => [
                                                'target'  => 'done',
                                                'actions' => 'storeOptionAction',
                                            ],
                                        ],
                                    ],
                                    'done' => ['type' => 'final'],
                                ],
                            ],
                            'documents' => [
                                'initial' => 'awaiting_docs',
                                'states'  => [
                                    'awaiting_docs' => [
                                        'on' => [
                                            'DOCS_UPLOADED' => 'done',
                                        ],
                                    ],
                                    'done' => ['type' => 'final'],
                                ],
                            ],
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'storeInputAction' => function (ContextManager $ctx): void {
                        $ctx->set('inputData', 'vehicle-vin-123');
                    },
                    'calculateAction' => function (ContextManager $ctx): void {
                        // This is the critical action — writes during @always in parallel region
                        $ctx->set('calculatedData', [
                            'basePrice'      => 100000,
                            'vatAmount'      => 18000,
                            'totalPrice'     => 118000,
                            'monthlyPayment' => 3277,
                        ]);
                    },
                    'storeOptionAction' => function (ContextManager $ctx): void {
                        $ctx->set('selectedOption', '36-months');
                    },
                ],
            ],
        );
    }
}
