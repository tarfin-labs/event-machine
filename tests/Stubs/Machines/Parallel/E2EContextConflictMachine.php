<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\ProcessSharedKeyAAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\ProcessSharedKeyBAction;

/**
 * Both regions write to the same context keys (sharedScalar, sharedArray).
 * Tests context merge/conflict behavior (last-writer-wins for scalars,
 * deep merge for arrays with different keys).
 */
class E2EContextConflictMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'e2e_context_conflict',
                'initial'        => 'processing',
                'should_persist' => true,
                'context'        => [
                    'sharedScalar' => null,
                    'sharedArray'  => [],
                    'regionAWrote' => null,
                    'regionBWrote' => null,
                ],
                'states' => [
                    'processing' => [
                        'type'   => 'parallel',
                        '@done'  => 'completed',
                        '@fail'  => 'failed',
                        'states' => [
                            'region_a' => [
                                'initial' => 'working',
                                'states'  => [
                                    'working' => [
                                        'entry' => ProcessSharedKeyAAction::class,
                                        'on'    => ['REGION_A_PROCESSED' => 'finished'],
                                    ],
                                    'finished' => ['type' => 'final'],
                                ],
                            ],
                            'region_b' => [
                                'initial' => 'working',
                                'states'  => [
                                    'working' => [
                                        'entry' => ProcessSharedKeyBAction::class,
                                        'on'    => ['REGION_B_PROCESSED' => 'finished'],
                                    ],
                                    'finished' => ['type' => 'final'],
                                ],
                            ],
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
        );
    }
}
