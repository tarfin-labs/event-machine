<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Jobs\ProcessJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Events\ConfirmEvent;

/**
 * Child machine for forward endpoint scenario testing.
 *
 * idle → @always → processing (job, @done→awaiting_input)
 * → awaiting_input (interactive) → CONFIRM → finalizing (job, @done→completed)
 * → completed (final)
 */
class ScenarioForwardChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'scenario_forward_child',
                'initial' => 'idle',
                'context' => ScenarioTestContext::class,
                'states'  => [
                    'idle' => [
                        'on' => [
                            '@always' => 'processing',
                        ],
                    ],
                    'processing' => [
                        'job'   => ProcessJob::class,
                        '@done' => 'awaiting_input',
                        '@fail' => 'failed',
                    ],
                    'awaiting_input' => [
                        'on' => [
                            'CONFIRM' => 'finalizing',
                        ],
                    ],
                    'finalizing' => [
                        'job'   => ProcessJob::class,
                        '@done' => 'completed',
                        '@fail' => 'failed',
                    ],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'CONFIRM' => ConfirmEvent::class,
                ],
            ],
        );
    }
}
