<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\ProcessRegionADeepReportAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\ProcessRegionBDeepReportAction;

/**
 * E2E test machine for deep nested context merge verification.
 *
 * Region A writes report.findeks, Region B writes report.turmob.
 * After both complete, report should contain both nested keys.
 */
class E2EDeepContextMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'e2e_deep_context',
                'initial'        => 'processing',
                'should_persist' => true,
                'context'        => [
                    'report'          => [],
                    'region_a_result' => null,
                    'region_b_result' => null,
                ],
                'states' => [
                    'processing' => [
                        'type'   => 'parallel',
                        '@done'  => 'completed',
                        'states' => [
                            'region_a' => [
                                'initial' => 'working',
                                'states'  => [
                                    'working' => [
                                        'entry' => ProcessRegionADeepReportAction::class,
                                        'on'    => ['REGION_A_PROCESSED' => 'finished'],
                                    ],
                                    'finished' => ['type' => 'final'],
                                ],
                            ],
                            'region_b' => [
                                'initial' => 'working',
                                'states'  => [
                                    'working' => [
                                        'entry' => ProcessRegionBDeepReportAction::class,
                                        'on'    => ['REGION_B_PROCESSED' => 'finished'],
                                    ],
                                    'finished' => ['type' => 'final'],
                                ],
                            ],
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
            ]
        );
    }
}
