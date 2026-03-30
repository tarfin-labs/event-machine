<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Top-level parent machine for three-level delegation tests.
 *
 * Flow: idle → (START) → processing [MiddleChildMachine async] → (@done) → completed
 * Delegates to MiddleChildMachine, which itself delegates to GrandchildMachine.
 */
class ThreeLevelParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'three_level_parent',
                'initial' => 'idle',
                'context' => [
                    'output' => null,
                    'error'  => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'processing'],
                    ],
                    'processing' => [
                        'machine' => MiddleChildMachine::class,
                        'queue'   => 'child-queue',
                        '@done'   => [
                            'target'  => 'completed',
                            'actions' => 'captureOutputAction',
                        ],
                        '@fail' => [
                            'target'  => 'failed',
                            'actions' => 'captureErrorAction',
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'captureOutputAction' => function (ContextManager $ctx, EventBehavior $event): void {
                        $ctx->set('childOutput', $event->payload['output'] ?? null);
                    },
                    'captureErrorAction' => function (ContextManager $ctx, EventBehavior $event): void {
                        $ctx->set('error', $event->payload['error_message'] ?? 'unknown');
                    },
                ],
            ],
        );
    }
}
