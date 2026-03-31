<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\QA;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Outputs\ApprovalOutput;
use Tarfinlabs\EventMachine\Tests\Stubs\Outputs\RejectionOutput;

/**
 * QA discriminated child that auto-approves via entry action.
 *
 * Sets required context (approvalId, approvedBy) in entry action,
 * then @always transitions to 'approved' final state with ApprovalOutput.
 * Used for testing discriminated @done.approved routing via Horizon.
 */
class QADiscriminatedChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'qa_discriminated_child',
                'initial' => 'reviewing',
                'context' => [
                    'approvalId' => null,
                    'approvedBy' => null,
                    'reason'     => null,
                    'reviewerId' => null,
                ],
                'states' => [
                    'reviewing' => [
                        'entry' => 'setApprovalDataAction',
                        'on'    => [
                            '@always' => 'approved',
                        ],
                    ],
                    'approved' => [
                        'type'   => 'final',
                        'output' => ApprovalOutput::class,
                    ],
                    'rejected' => [
                        'type'   => 'final',
                        'output' => RejectionOutput::class,
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'setApprovalDataAction' => function (ContextManager $ctx): void {
                        $ctx->set('approvalId', 'APR-QA-001');
                        $ctx->set('approvedBy', 'reviewer_qa');
                    },
                ],
            ],
        );
    }
}
