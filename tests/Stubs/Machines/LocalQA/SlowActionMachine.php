<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\LocalQA;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Machine with a deliberately slow action for deadlock testing.
 *
 * PROCESS event triggers a 3-second sleep action. INTERRUPT can be sent
 * concurrently to test lock contention behavior.
 */
class SlowActionMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'slow_action',
                'initial' => 'idle',
                'context' => [
                    'processed'   => false,
                    'interrupted' => false,
                ],
                'states' => [
                    'idle' => [
                        'on' => [
                            'PROCESS' => [
                                'target'  => 'processing',
                                'actions' => 'slowAction',
                            ],
                            'INTERRUPT' => [
                                'target'  => 'interrupted',
                                'actions' => 'markInterruptedAction',
                            ],
                        ],
                    ],
                    'processing' => [
                        'on' => [
                            'INTERRUPT' => [
                                'target'  => 'interrupted',
                                'actions' => 'markInterruptedAction',
                            ],
                        ],
                    ],
                    'interrupted' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'slowAction' => function (ContextManager $context): void {
                        sleep(3); // Deliberately slow
                        $context->set('processed', true);
                    },
                    'markInterruptedAction' => function (ContextManager $context): void {
                        $context->set('interrupted', true);
                    },
                ],
            ],
        );
    }
}
