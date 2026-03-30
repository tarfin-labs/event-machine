<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\QA;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * QA parent for discriminated @done routing via Horizon.
 *
 * Delegates to QADiscriminatedChildMachine which auto-approves.
 * Routes: @done.approved → completed, @done.rejected → under_review.
 */
class QADiscriminatedParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'qa_discriminated_parent',
                'initial' => 'idle',
                'context' => [
                    'childOutput' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'reviewing'],
                    ],
                    'reviewing' => [
                        'machine'        => QADiscriminatedChildMachine::class,
                        'queue'          => 'child-queue',
                        '@done.approved' => [
                            'target'  => 'completed',
                            'actions' => 'captureOutputAction',
                        ],
                        '@done.rejected' => ['target' => 'under_review'],
                        '@fail'          => ['target' => 'errored'],
                    ],
                    'completed'    => ['type' => 'final'],
                    'under_review' => ['type' => 'final'],
                    'errored'      => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'captureOutputAction' => function (ContextManager $ctx, EventBehavior $event): void {
                        $ctx->set('childOutput', $event->payload['output'] ?? null);
                    },
                ],
            ],
        );
    }
}
