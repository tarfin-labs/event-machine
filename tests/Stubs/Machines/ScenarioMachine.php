<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class ScenarioMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'initial' => 'stateA',
                'scenario_enabled' => true,
                'context' => [
                    'count' => 1,
                ],
                'states' => [
                    'stateA' => [
                        'on' => [
                            'EVENT_B' => [
                                'target' => 'stateB',
                                'actions' => 'incrementAction'
                            ]
                        ],
                    ],
                    'stateB' => [
                        'on' => [
                            'EVENT_C' => 'stateC',
                        ],
                    ],
                    'stateC' => [],
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
                    'stateA' => [
                        'on' => [
                            'EVENT_B' => [
                                'target' => 'stateC',
                                'actions' => 'decrementAction'
                            ]
                        ],
                    ],
                ],
            ]
        );
    }
}
