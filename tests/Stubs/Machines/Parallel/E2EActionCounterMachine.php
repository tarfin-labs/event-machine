<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Machine that counts entry/exit actions via context counters.
 *
 * Transitions: idle → processing → idle (loops) with entry/exit actions
 * that increment counters. Used to verify actions execute exactly once
 * per transition under concurrent sends.
 */
class E2EActionCounterMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'e2e_action_counter',
                'initial'        => 'idle',
                'should_persist' => true,
                'context'        => [
                    'entryCount' => 0,
                    'exitCount'  => 0,
                    'eventLog'   => [],
                ],
                'states' => [
                    'idle' => [
                        'entry' => 'incrementEntryAction',
                        'exit'  => 'incrementExitAction',
                        'on'    => [
                            'PROCESS' => 'processing',
                        ],
                    ],
                    'processing' => [
                        'entry' => 'incrementEntryAction',
                        'exit'  => 'incrementExitAction',
                        'on'    => [
                            'COMPLETE' => 'idle',
                            'FINISH'   => 'completed',
                        ],
                    ],
                    'completed' => [
                        'entry' => 'incrementEntryAction',
                        'type'  => 'final',
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'incrementEntryAction' => function (ContextManager $context): void {
                        $context->set('entryCount', $context->get('entryCount') + 1);
                    },
                    'incrementExitAction' => function (ContextManager $context): void {
                        $context->set('exitCount', $context->get('exitCount') + 1);
                    },
                ],
            ],
        );
    }
}
