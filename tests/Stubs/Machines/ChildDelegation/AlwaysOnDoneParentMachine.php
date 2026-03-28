<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Parent machine where the @done target state has an @always transition.
 *
 * Flow: idle → processing (async child) → @done → routing (@always → completed)
 * Used for testing that @always transitions are followed after child completion.
 */
class AlwaysOnDoneParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'always_on_done_parent',
                'initial' => 'idle',
                'context' => [
                    'result' => null,
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
                            'target'  => 'routing',
                            'actions' => 'captureResultAction',
                        ],
                        '@fail' => [
                            'target' => 'failed',
                        ],
                    ],
                    'routing' => [
                        'on' => [
                            '@always' => 'completed',
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'captureResultAction' => function (ContextManager $ctx, EventBehavior $event): void {
                        $ctx->set('result', $event->payload['output'] ?? null);
                    },
                ],
            ],
        );
    }
}
