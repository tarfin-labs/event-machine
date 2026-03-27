<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Guards\AlwaysFailDispatchIsolationValidationGuard;

/**
 * Machine with two parallel regions:
 * - region_with_guard: has a ValidationGuard that always fails on SUBMIT
 * - region_without_guard: no guard, transitions freely
 *
 * Used to verify that a ValidationGuard failure in one region
 * does NOT corrupt or modify the sibling region's state.
 */
class ValidationGuardDispatchIsolationMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'vg_dispatch_isolation',
                'initial' => 'collecting',
                'context' => [
                    'guardedRegionEntered' => false,
                    'siblingRegionEntered' => false,
                ],
                'states' => [
                    'collecting' => [
                        'type'   => 'parallel',
                        '@done'  => 'completed',
                        'states' => [
                            'region_with_guard' => [
                                'initial' => 'awaiting_input',
                                'states'  => [
                                    'awaiting_input' => [
                                        'on' => [
                                            'SUBMIT' => [
                                                'target'  => 'accepted',
                                                'guards'  => AlwaysFailDispatchIsolationValidationGuard::class,
                                                'actions' => 'markGuardedRegionEnteredAction',
                                            ],
                                            'SUBMIT_VALID' => [
                                                'target'  => 'accepted',
                                                'actions' => 'markGuardedRegionEnteredAction',
                                            ],
                                        ],
                                    ],
                                    'accepted' => ['type' => 'final'],
                                ],
                            ],
                            'region_without_guard' => [
                                'initial' => 'idle',
                                'states'  => [
                                    'idle' => [
                                        'on' => [
                                            'SUBMIT' => [
                                                'target'  => 'done',
                                                'actions' => 'markSiblingRegionEnteredAction',
                                            ],
                                            'SUBMIT_VALID' => [
                                                'target'  => 'done',
                                                'actions' => 'markSiblingRegionEnteredAction',
                                            ],
                                        ],
                                    ],
                                    'done' => ['type' => 'final'],
                                ],
                            ],
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'guards' => [
                    AlwaysFailDispatchIsolationValidationGuard::class,
                ],
                'actions' => [
                    'markGuardedRegionEnteredAction' => function (ContextManager $context): void {
                        $context->set('guardedRegionEntered', true);
                    },
                    'markSiblingRegionEnteredAction' => function (ContextManager $context): void {
                        $context->set('siblingRegionEntered', true);
                    },
                ],
            ],
        );
    }
}
