<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Events\ApproveEvent;

/**
 * Parent machine with forward delegation for scenario testing.
 *
 * idle → @always → routing → @always → delegating (machine: ScenarioForwardChildMachine)
 *   forward: CONFIRM → /confirm
 *
 *   @done → completed (FINAL)
 *
 *   @fail → failed (FINAL)
 */
class ScenarioForwardParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'scenario_forward_parent',
                'initial'        => 'idle',
                'should_persist' => true,
                'context'        => ScenarioTestContext::class,
                'states'         => [
                    'idle' => [
                        'on' => [
                            '@always' => 'delegating',
                        ],
                    ],
                    'delegating' => [
                        'machine' => ScenarioForwardChildMachine::class,
                        'forward' => [
                            'CONFIRM' => '/confirm',
                        ],
                        '@done' => 'completed',
                        '@fail' => 'failed',
                    ],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'APPROVE' => ApproveEvent::class,
                ],
            ],
            endpoints: [
                'APPROVE',
            ],
        );
    }
}
