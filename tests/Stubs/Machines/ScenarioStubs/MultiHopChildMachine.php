<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Jobs\ProcessJob;

/**
 * Multi-hop stub for child scenario @continue + outcomes testing.
 *
 * idle → @always → first_job (job actor, @done→review)
 * → review (interactive) → APPROVE → second_job (job actor, @done→completed)
 * → completed (final)
 */
class MultiHopChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'multi_hop_child',
                'initial' => 'idle',
                'context' => ScenarioTestContext::class,
                'states'  => [
                    'idle' => [
                        'on' => [
                            '@always' => 'first_job',
                        ],
                    ],
                    'first_job' => [
                        'job'   => ProcessJob::class,
                        '@done' => 'review',
                        '@fail' => 'failed',
                    ],
                    'review' => [
                        'on' => [
                            'APPROVE' => 'second_job',
                        ],
                    ],
                    'second_job' => [
                        'job'   => ProcessJob::class,
                        '@done' => 'completed',
                        '@fail' => 'failed',
                    ],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
        );
    }
}
