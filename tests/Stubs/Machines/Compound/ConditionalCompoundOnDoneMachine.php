<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Compound;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\LogApprovalAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Guards\IsAllSucceededGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\NotifyReviewerAction;

/**
 * A compound state machine with conditional @done guards.
 *
 * A compound parent with child states. When the child reaches final,
 * the compound @done fires with guard-based routing.
 */
class ConditionalCompoundOnDoneMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'conditional_compound_on_done',
                'initial' => 'verification',
                'context' => [
                    'inventory_result'  => null,
                    'payment_result'    => null,
                    'approval_logged'   => false,
                    'reviewer_notified' => false,
                ],
                'states' => [
                    'verification' => [
                        '@done' => [
                            ['target' => 'approved',      'guards' => IsAllSucceededGuard::class, 'actions' => LogApprovalAction::class],
                            ['target' => 'manual_review', 'actions' => NotifyReviewerAction::class],
                        ],
                        'initial' => 'checking',
                        'states'  => [
                            'checking' => [
                                'on' => [
                                    'CHECK_COMPLETED' => 'done',
                                ],
                            ],
                            'done' => ['type' => 'final'],
                        ],
                    ],
                    'approved'      => ['type' => 'final'],
                    'manual_review' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
            ]
        );
    }
}
