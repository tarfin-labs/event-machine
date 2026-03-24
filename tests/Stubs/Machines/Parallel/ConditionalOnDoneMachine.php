<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\LogApprovalAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Guards\IsAllSucceededGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\NotifyReviewerAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\SetPaymentSuccessAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\SetInventorySuccessAction;

/**
 * A parallel state machine with conditional @done guards.
 *
 * Two regions (inventory + payment) that set context on completion.
 *
 * @done has guards: if all succeeded → approved, otherwise → manual_review.
 */
class ConditionalOnDoneMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'conditional_on_done',
                'initial' => 'processing',
                'context' => [
                    'inventory_result'  => null,
                    'payment_result'    => null,
                    'approval_logged'   => false,
                    'reviewer_notified' => false,
                ],
                'states' => [
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
            behavior: [
                'context' => GenericContext::class,
            ]
        );
    }
}
