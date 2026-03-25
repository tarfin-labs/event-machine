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
                    'entry_count' => 0,
                    'exit_count'  => 0,
                    'event_log'   => [],
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
                        $context->set('entry_count', $context->get('entry_count') + 1);
                    },
                    'incrementExitAction' => function (ContextManager $context): void {
                        $context->set('exit_count', $context->get('exit_count') + 1);
                    },
                ],
            ],
        );
    }
}
