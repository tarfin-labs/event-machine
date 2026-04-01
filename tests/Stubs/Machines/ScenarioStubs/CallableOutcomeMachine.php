<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Jobs\ProcessJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Events\ConfirmEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Guards\IsRetryableGuard;

/**
 * Minimal machine for callable outcome testing.
 *
 * idle → @always → waiting (INTERACTIVE)
 *   → CONFIRM → confirming (DELEGATION, job: ProcessJob)
 *
 *      @done → completed (FINAL)
 *
 *      @fail → [IsRetryableGuard=true] → waiting (retry)
 *            → [IsRetryableGuard=false] → failed (FINAL)
 *
 *      @timeout → timed_out (FINAL)
 */
class CallableOutcomeMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'callable_outcome_test',
                'initial' => 'idle',
                'context' => ScenarioTestContext::class,
                'states'  => [
                    'idle' => [
                        'on' => [
                            '@always' => 'waiting',
                        ],
                    ],
                    'waiting' => [
                        'on' => [
                            'CONFIRM' => 'confirming',
                        ],
                    ],
                    'confirming' => [
                        'job'   => ProcessJob::class,
                        '@done' => 'completed',
                        '@fail' => [
                            [
                                'target' => 'waiting',
                                'guards' => IsRetryableGuard::class,
                            ],
                            [
                                'target' => 'failed',
                            ],
                        ],
                        '@timeout' => [
                            'target'  => 'timed_out',
                            'timeout' => 5000,
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                    'timed_out' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'CONFIRM' => ConfirmEvent::class,
                ],
            ],
            endpoints: [
                'CONFIRM',
            ],
        );
    }
}
