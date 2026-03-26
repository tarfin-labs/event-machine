<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Guards\AlwaysFailGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Guards\IsValuePositiveValidationGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Guards\AlwaysFailParallelValidationGuard;

class ValidationGuardParallelMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'validation_guard_parallel',
                'initial' => 'collecting',
                'context' => [
                    'value' => 0,
                ],
                'states' => [
                    'collecting' => [
                        'type'  => 'parallel',
                        '@done' => 'completed',
                        'on'    => [
                            // Escape transition at parallel level with regular guard (always fails)
                            'ESCAPE_WITH_GUARD' => [
                                'target' => 'completed',
                                'guards' => AlwaysFailGuard::class,
                            ],
                        ],
                        'states' => [
                            'data_entry' => [
                                'initial' => 'awaiting_input',
                                'states'  => [
                                    'awaiting_input' => [
                                        'on' => [
                                            'SUBMIT_DATA' => [
                                                'target' => 'data_received',
                                                'guards' => IsValuePositiveValidationGuard::class,
                                            ],
                                            'SUBMIT_ALWAYS_FAIL' => [
                                                'target' => 'data_received',
                                                'guards' => AlwaysFailParallelValidationGuard::class,
                                            ],
                                            'SUBMIT_REGULAR_GUARD_FAIL' => [
                                                'target' => 'data_received',
                                                'guards' => AlwaysFailGuard::class,
                                            ],
                                        ],
                                    ],
                                    'data_received' => ['type' => 'final'],
                                ],
                            ],
                            'review' => [
                                'initial' => 'pending_review',
                                'states'  => [
                                    'pending_review' => [
                                        'on' => [
                                            'SUBMIT_DATA'        => ['target' => 'reviewed'],
                                            'SUBMIT_ALWAYS_FAIL' => ['target' => 'reviewed'],
                                        ],
                                    ],
                                    'reviewed' => ['type' => 'final'],
                                ],
                            ],
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'guards' => [
                    IsValuePositiveValidationGuard::class,
                    AlwaysFailParallelValidationGuard::class,
                    AlwaysFailGuard::class,
                ],
            ],
        );
    }
}
