<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Machine with parallel state containing child machine delegation inside a region.
 * Used for deep target command testing.
 *
 * idle → START → verification (PARALLEL)
 *   → region_check:
 *       running (DELEGATION, machine: ScenarioTestChildMachine)
 *         → @done → check_done (FINAL)
 *   → region_other:
 *       other_running → @always → other_done (FINAL)
 *   → @done → completed (FINAL)
 */
class DeepTargetTestMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(config: [
            'id'      => 'deep_target_test',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => [
                        'START' => 'verification',
                    ],
                ],
                'verification' => [
                    'type'   => 'parallel',
                    '@done'  => 'completed',
                    'states' => [
                        'region_check' => [
                            'initial' => 'running',
                            'states'  => [
                                'running' => [
                                    'machine' => ScenarioTestChildMachine::class,
                                    '@done'   => 'check_done',
                                ],
                                'check_done' => ['type' => 'final'],
                            ],
                        ],
                        'region_other' => [
                            'initial' => 'other_running',
                            'states'  => [
                                'other_running' => [
                                    'on' => [
                                        '@always' => 'other_done',
                                    ],
                                ],
                                'other_done' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ]);
    }
}
