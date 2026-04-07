<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class PersistenceFidelityMultiEventMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'pf_multi_event',
                'initial' => 'step1',
                'context' => [
                    'counter' => 0,
                ],
                'states' => [
                    'step1' => [
                        'on' => [
                            'GO' => [
                                'target'  => 'step2',
                                'actions' => 'incrementAction',
                            ],
                        ],
                    ],
                    'step2' => [
                        'on' => [
                            'GO' => [
                                'target'  => 'step3',
                                'actions' => 'incrementAction',
                            ],
                        ],
                    ],
                    'step3' => [
                        'on' => [
                            'GO' => [
                                'target'  => 'step4',
                                'actions' => 'incrementAction',
                            ],
                        ],
                    ],
                    'step4' => [
                        'on' => [
                            'GO' => [
                                'target'  => 'step5',
                                'actions' => 'incrementAction',
                            ],
                        ],
                    ],
                    'step5' => [
                        'on' => [
                            'GO' => [
                                'target'  => 'step6',
                                'actions' => 'incrementAction',
                            ],
                        ],
                    ],
                    'step6' => [],
                ],
            ],
            behavior: [
                'actions' => [
                    'incrementAction' => function (ContextManager $context): void {
                        $context->set('counter', $context->get('counter') + 1);
                    },
                ],
            ],
        );
    }
}
