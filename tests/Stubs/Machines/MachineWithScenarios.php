<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * @deprecated Test stub for the old scenario system. Will be removed in next major version.
 */
class MachineWithScenarios extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'initial'           => 'state_a',
                'scenarios_enabled' => true,
                'context'           => [
                    'count' => 1,
                ],
                'states' => [
                    'state_a' => [
                        'on' => [
                            'EVENT_B' => [
                                'target'  => 'state_b',
                                'actions' => 'incrementAction',
                            ],
                        ],
                    ],
                    'state_b' => [
                        'on' => [
                            'EVENT_C' => 'state_c',
                        ],
                    ],
                    'state_c' => [
                        'on' => [
                            'EVENT_D' => [
                                'target'  => 'state_d',
                                'actions' => 'incrementAction',
                            ],
                        ],
                    ],
                    'state_d' => [],
                ],
            ],
            behavior: [
                'actions' => [
                    'incrementAction' => function (ContextManager $context): void {
                        $context->set('count', $context->get('count') + 1);
                    },
                    'decrementAction' => function (ContextManager $context): void {
                        $context->set('count', $context->get('count') - 1);
                    },
                ],
            ],
            scenarios: [
                'test' => [
                    'state_a' => [
                        'on' => [
                            'EVENT_B' => [
                                'target'  => 'state_c',
                                'actions' => 'decrementAction',
                            ],
                        ],
                        'exit' => [
                            'decrementAction',
                        ],
                    ],
                    'state_c' => [
                        'entry' => [
                            'decrementAction',
                        ],
                        'on' => [
                            'EVENT_D' => [
                                'target'  => 'state_a',
                                'actions' => 'decrementAction',
                            ],
                        ],
                    ],
                ],
            ]
        );
    }
}
