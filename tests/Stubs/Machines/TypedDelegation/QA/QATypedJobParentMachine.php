<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\QA;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Jobs\TypedSuccessfulJob;

/**
 * QA parent machine that delegates to TypedSuccessfulJob via Horizon.
 *
 * TypedSuccessfulJob implements ReturnsOutput (returns PaymentOutput)
 * and ProvidesFailure (returns PaymentFailure).
 * Tests that typed MachineOutput from a job survives queue serialization.
 */
class QATypedJobParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'qa_typed_job_parent',
                'initial' => 'idle',
                'context' => [
                    'orderId'   => 'ORD-JOB-QA',
                    'paymentId' => null,
                    'status'    => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'processing'],
                    ],
                    'processing' => [
                        'job'   => TypedSuccessfulJob::class,
                        'input' => ['orderId'],
                        'queue' => 'default',
                        '@done' => [
                            'target'  => 'completed',
                            'actions' => 'captureJobOutputAction',
                        ],
                        '@fail' => [
                            'target'  => 'errored',
                            'actions' => 'captureJobErrorAction',
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                    'errored'   => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'captureJobOutputAction' => function (ContextManager $ctx, EventBehavior $event): void {
                        $output = $event->payload['output'] ?? [];
                        $ctx->set('paymentId', $output['paymentId'] ?? null);
                        $ctx->set('status', $output['status'] ?? null);
                    },
                    'captureJobErrorAction' => function (ContextManager $ctx, EventBehavior $event): void {
                        $ctx->set('error', $event->payload['error_message'] ?? 'unknown');
                    },
                ],
            ],
        );
    }
}
