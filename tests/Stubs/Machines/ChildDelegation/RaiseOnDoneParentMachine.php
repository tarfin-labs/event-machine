<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Parent machine that raises an event in the @done target state's entry action.
 *
 * Flow: idle → processing (async child) → @done → received (entry: raise NEXT) → NEXT → completed
 * Used for testing that raised events are processed after child completion.
 */
class RaiseOnDoneParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'raise_on_done_parent',
                'initial' => 'idle',
                'context' => [
                    'output' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => [
                            'START' => 'processing',
                        ],
                    ],
                    'processing' => [
                        'machine' => SimpleChildMachine::class,
                        'queue'   => 'child-queue',
                        '@done'   => [
                            'target'  => 'received',
                            'actions' => 'captureResultAction',
                        ],
                        '@fail' => [
                            'target' => 'failed',
                        ],
                    ],
                    'received' => [
                        'entry' => 'raiseNextAction',
                        'on'    => [
                            'NEXT' => 'completed',
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'captureResultAction' => function (ContextManager $ctx): void {
                        // no-op: just transitions to target
                    },
                    'raiseNextAction' => RaiseNextAction::class,
                ],
            ],
        );
    }
}
