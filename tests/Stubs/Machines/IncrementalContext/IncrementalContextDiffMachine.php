<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\IncrementalContext;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class IncrementalContextDiffMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'incremental_context_diff',
                'initial' => 'step1',
                'context' => [
                    'key_a' => 'initial_a',
                    'key_b' => 'initial_b',
                    'key_c' => 'initial_c',
                ],
                'states' => [
                    'step1' => [
                        'on' => [
                            'GO1' => [
                                'target'  => 'step2',
                                'actions' => 'setKeyA1Action',
                            ],
                        ],
                    ],
                    'step2' => [
                        'on' => [
                            'GO2' => [
                                'target'  => 'step3',
                                'actions' => 'setKeyBAndKeyA2Action',
                            ],
                        ],
                    ],
                    'step3' => [],
                ],
            ],
            behavior: [
                'actions' => [
                    'setKeyA1Action' => function (ContextManager $context): void {
                        $context->set('key_a', 'updated_a_1');
                    },
                    'setKeyBAndKeyA2Action' => function (ContextManager $context): void {
                        $context->set('key_b', 'updated_b');
                        $context->set('key_a', 'updated_a_2');
                    },
                ],
            ],
        );
    }
}
