<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\JobActors;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Jobs\SuccessfulTestJob;

/**
 * Parent machine that delegates to a job actor (SuccessfulTestJob).
 *
 * Flow: idle → (START) → processing [job: SuccessfulTestJob] → (@done) → completed
 */
class JobActorParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'job_actor_parent',
                'initial' => 'idle',
                'context' => [
                    'order_id'   => 'ord_001',
                    'payment_id' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'processing'],
                    ],
                    'processing' => [
                        'job'   => SuccessfulTestJob::class,
                        'with'  => ['order_id'],
                        'queue' => 'default',
                        '@done' => [
                            'target'  => 'completed',
                            'actions' => 'capturePaymentAction',
                        ],
                        '@fail' => 'failed',
                    ],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'capturePaymentAction' => function (ContextManager $ctx, EventBehavior $event): void {
                        $ctx->set('payment_id', $event->payload['output']['payment_id'] ?? null);
                    },
                ],
            ],
        );
    }
}
