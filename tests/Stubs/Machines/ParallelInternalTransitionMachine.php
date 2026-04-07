<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class ParallelInternalTransitionMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'parallel_internal_transition',
                'initial' => 'processing',
                'context' => [
                    'valueFromAction' => null,
                ],
                'states' => [
                    'processing' => [
                        'type'   => 'parallel',
                        '@done'  => 'completed',
                        'states' => [
                            'region_a' => [
                                'initial' => 'step_1',
                                'states'  => [
                                    'step_1' => [
                                        'on' => [
                                            'GO' => [
                                                'target'  => 'step_2',
                                                'actions' => 'setValueAction',
                                            ],
                                        ],
                                    ],
                                    'step_2' => [
                                        'on' => ['FINISH_A' => 'done'],
                                    ],
                                    'done' => ['type' => 'final'],
                                ],
                            ],
                            'region_b' => [
                                'initial' => 'idle',
                                'states'  => [
                                    'idle' => [
                                        'on' => ['FINISH_B' => 'done'],
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
                    'setValueAction' => function (ContextManager $ctx): void {
                        $ctx->set('valueFromAction', 42);
                    },
                ],
            ],
        );
    }
}
