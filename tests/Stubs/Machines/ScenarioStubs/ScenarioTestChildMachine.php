<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Jobs\ProcessJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Guards\IsValidGuard;

/**
 * Child machine with transient initial state (for @start testing).
 *
 * idle (TRANSIENT) → @always → verifying (DELEGATION, job: ProcessJob)
 *   → @done → [IsValidGuard=true]  → verified (FINAL)
 *   → @done → [IsValidGuard=false] → unverified (FINAL)
 *   → @fail → child_failed (FINAL)
 */
class ScenarioTestChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'scenario_test_child',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => [
                            '@always' => 'verifying',
                        ],
                    ],
                    'verifying' => [
                        'job'   => ProcessJob::class,
                        '@done' => [
                            [
                                'guards' => IsValidGuard::class,
                                'target' => 'verified',
                            ],
                            [
                                'target' => 'unverified',
                            ],
                        ],
                        '@fail' => 'child_failed',
                    ],
                    'verified'     => ['type' => 'final'],
                    'unverified'   => ['type' => 'final'],
                    'child_failed' => ['type' => 'final'],
                ],
            ],
        );
    }
}
