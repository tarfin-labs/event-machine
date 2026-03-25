<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\LogApprovalAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Guards\IsAllSucceededGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\NotifyReviewerAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\SetPaymentSuccessAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\SetInventorySuccessAction;

/**
 * QA variant of ConditionalOnDoneMachine that starts in 'idle' state.
 * Transitions into parallel state via START event — required for
 * parallel dispatch mode (ParallelRegionJobs only fire on transition, not on init).
 */
class ConditionalOnDoneQAMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'conditional_on_done_qa',
                'initial'        => 'idle',
                'should_persist' => true,
                'context'        => [
                    'inventoryResult'  => null,
                    'paymentResult'    => null,
                    'approvalLogged'   => false,
                    'reviewerNotified' => false,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'processing'],
                    ],
                    'processing' => [
                        'type'  => 'parallel',
                        '@done' => [
                            ['target' => 'approved',      'guards' => IsAllSucceededGuard::class, 'actions' => LogApprovalAction::class],
                            ['target' => 'manual_review', 'actions' => NotifyReviewerAction::class],
                        ],
                        'states' => [
                            'inventory' => [
                                'initial' => 'checking',
                                'states'  => [
                                    'checking' => [
                                        'entry' => SetInventorySuccessAction::class,
                                        'on'    => [
                                            'INVENTORY_CHECKED' => 'done',
                                        ],
                                    ],
                                    'done' => ['type' => 'final'],
                                ],
                            ],
                            'payment' => [
                                'initial' => 'validating',
                                'states'  => [
                                    'validating' => [
                                        'entry' => SetPaymentSuccessAction::class,
                                        'on'    => [
                                            'PAYMENT_VALIDATED' => 'done',
                                        ],
                                    ],
                                    'done' => ['type' => 'final'],
                                ],
                            ],
                        ],
                    ],
                    'approved'      => ['type' => 'final'],
                    'manual_review' => ['type' => 'final'],
                ],
            ],
        );
    }
}
