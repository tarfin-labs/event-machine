<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class PersistenceFidelityMultiActionMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'pf_multi_action',
                'initial' => 'idle',
                'context' => [
                    'alpha'   => null,
                    'beta'    => null,
                    'gamma'   => null,
                    'counter' => 0,
                ],
                'states' => [
                    'idle' => [
                        'on' => [
                            'TRIGGER' => [
                                'target'  => 'done',
                                'actions' => [
                                    'setAlphaAction',
                                    'setBetaAction',
                                    'setGammaAndIncrementAction',
                                ],
                            ],
                        ],
                    ],
                    'done' => [],
                ],
            ],
            behavior: [
                'actions' => [
                    'setAlphaAction' => function (ContextManager $context): void {
                        $context->set('alpha', 'value_a');
                    },
                    'setBetaAction' => function (ContextManager $context): void {
                        $context->set('beta', 'value_b');
                    },
                    'setGammaAndIncrementAction' => function (ContextManager $context): void {
                        $context->set('gamma', 'value_g');
                        $context->set('counter', 1);
                    },
                ],
            ],
        );
    }
}
