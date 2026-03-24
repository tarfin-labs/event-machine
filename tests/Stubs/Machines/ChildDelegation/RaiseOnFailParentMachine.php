<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;

/**
 * Parent machine that raises an event in the @fail target state's entry action.
 *
 * Flow: idle → processing (async child) → @fail → error_received (entry: raise HANDLE_ERROR) → HANDLE_ERROR → handled
 * Used for testing that raised events are processed after child failure routing.
 */
class RaiseOnFailParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'raise_on_fail_parent',
                'initial' => 'idle',
                'context' => [
                    'error' => null,
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
                            'target' => 'completed',
                        ],
                        '@fail' => [
                            'target'  => 'error_received',
                            'actions' => 'captureErrorAction',
                        ],
                    ],
                    'error_received' => [
                        'entry' => 'raiseHandleErrorAction',
                        'on'    => [
                            'HANDLE_ERROR' => 'handled',
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                    'handled'   => ['type' => 'final'],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
                'actions' => [
                    'captureErrorAction' => function (ContextManager $ctx): void {
                        // no-op: just transitions to target
                    },
                    'raiseHandleErrorAction' => RaiseHandleErrorAction::class,
                ],
            ],
        );
    }
}
