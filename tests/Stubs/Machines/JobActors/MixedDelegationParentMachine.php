<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\JobActors;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Jobs\SuccessfulTestJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateChildMachine;

/**
 * Mixed delegation: machine delegation → @done → job actor → @done → completed.
 *
 * Flow: idle → (START) → delegating [machine: ImmediateChildMachine] → (@done) →
 *       processing [job: SuccessfulTestJob] → (@done) → completed
 *
 * Tests that a single parent can chain machine delegation and job actor sequentially.
 */
class MixedDelegationParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'mixed_delegation',
                'initial'        => 'idle',
                'should_persist' => true,
                'context'        => [
                    'orderId'     => 'ord_mixed_001',
                    'childOutput' => null,
                    'jobOutput'   => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'delegating'],
                    ],
                    'delegating' => [
                        'machine' => ImmediateChildMachine::class,
                        'queue'   => 'child-queue',
                        '@done'   => [
                            'target'  => 'processing',
                            'actions' => 'captureChildOutputAction',
                        ],
                        '@fail' => 'failed',
                    ],
                    'processing' => [
                        'job'   => SuccessfulTestJob::class,
                        'input' => ['orderId'],
                        'queue' => 'default',
                        '@done' => [
                            'target'  => 'completed',
                            'actions' => 'captureJobOutputAction',
                        ],
                        '@fail' => 'failed',
                    ],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'captureChildOutputAction' => function (ContextManager $ctx, EventBehavior $event): void {
                        $ctx->set('childOutput', 'child_done');
                    },
                    'captureJobOutputAction' => function (ContextManager $ctx, EventBehavior $event): void {
                        $ctx->set('jobOutput', $event->payload['output']['paymentId'] ?? 'no_output');
                    },
                ],
            ],
        );
    }
}
