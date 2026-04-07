<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class InitialAlwaysChainMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'initial_always_chain',
                'initial' => 'workflow',
                'context' => [
                    'actionLog' => [],
                ],
                'states' => [
                    'workflow' => [
                        'initial' => 'step_one',
                        'states'  => [
                            'step_one' => [
                                'entry' => 'logStepOneAction',
                                'on'    => [
                                    '@always' => [
                                        'target' => 'step_two',
                                        'guards' => 'alwaysTrueGuard',
                                    ],
                                ],
                            ],
                            'step_two' => [
                                'entry' => 'logStepTwoAction',
                                'on'    => [
                                    '@always' => [
                                        'target' => 'step_three',
                                        'guards' => 'alwaysTrueGuard',
                                    ],
                                ],
                            ],
                            'step_three' => [
                                'entry' => 'logStepThreeAction',
                            ],
                        ],
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'logStepOneAction' => function (ContextManager $context): void {
                        $log   = $context->get('actionLog');
                        $log[] = 'entry:step_one';
                        $context->set('actionLog', $log);
                    },
                    'logStepTwoAction' => function (ContextManager $context): void {
                        $log   = $context->get('actionLog');
                        $log[] = 'entry:step_two';
                        $context->set('actionLog', $log);
                    },
                    'logStepThreeAction' => function (ContextManager $context): void {
                        $log   = $context->get('actionLog');
                        $log[] = 'entry:step_three';
                        $context->set('actionLog', $log);
                    },
                ],
                'guards' => [
                    'alwaysTrueGuard' => fn (): bool => true,
                ],
            ],
        );
    }
}
