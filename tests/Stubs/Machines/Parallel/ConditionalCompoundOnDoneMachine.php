<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\LogApprovalAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Guards\IsAllSucceededGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\NotifyReviewerAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\SetInventorySuccessAction;

/**
 * A compound state machine with conditional @done guards.
 *
 * Single region (verification) with entry action setting context on completion.
 *
 * @done has guards: if all succeeded → approved, otherwise → manual_review.
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
                    'inventoryResult'  => null,
                    'paymentResult'    => null,
                    'approvalLogged'   => false,
                    'reviewerNotified' => false,
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
                                'entry' => SetInventorySuccessAction::class,
                                'on'    => ['CHECK_COMPLETED' => 'done'],
                            ],
                            'done' => ['type' => 'final'],
                        ],
                    ],
                    'approved'      => ['type' => 'final'],
                    'manual_review' => ['type' => 'final'],
                ],
            ],
        );
    }
}
