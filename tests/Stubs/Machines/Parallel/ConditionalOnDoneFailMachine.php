<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\LogApprovalAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Guards\IsAllSucceededGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\NotifyReviewerAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\SetPaymentFailureAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\SetInventorySuccessAction;

/**
 * QA variant of ConditionalOnDoneMachine but payment region sets 'failure'.
 * Starts in 'idle' for parallel dispatch mode (ParallelRegionJobs need transition entry).
 * Guard fails → routes to manual_review instead of approved.
 */
class ConditionalOnDoneFailMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'conditional_on_done_fail',
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
                                        'entry' => SetPaymentFailureAction::class,
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
