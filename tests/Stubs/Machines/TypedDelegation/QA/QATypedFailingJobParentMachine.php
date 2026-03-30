<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\QA;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Jobs\TypedFailingJob;

/**
 * QA parent machine that delegates to TypedFailingJob via Horizon.
 *
 * TypedFailingJob throws RuntimeException and implements ProvidesFailure
 * (returns PaymentFailure). Tests that typed failure from a job survives
 * queue serialization and is delivered to parent @fail.
 */
class QATypedFailingJobParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'qa_typed_failing_job_parent',
                'initial' => 'idle',
                'context' => [
                    'orderId' => 'ORD-FAIL-JOB-QA',
                    'error'   => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'processing'],
                    ],
                    'processing' => [
                        'job'   => TypedFailingJob::class,
                        'input' => ['orderId'],
                        'queue' => 'default',
                        '@done' => 'completed',
                        '@fail' => [
                            'target'  => 'errored',
                            'actions' => 'captureErrorAction',
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                    'errored'   => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'captureErrorAction' => function (ContextManager $ctx, EventBehavior $event): void {
                        $ctx->set('error', $event->payload['error_message'] ?? 'unknown');
                    },
                ],
            ],
        );
    }
}
