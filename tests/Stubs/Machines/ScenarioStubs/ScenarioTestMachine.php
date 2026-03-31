<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Jobs\ProcessJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Guards\IsValidGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Actions\ProcessAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Guards\IsEligibleGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Events\ApproveEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Events\RejectEvent;

/**
 * Minimal machine covering all 5 state classifications + child machine delegation.
 *
 * idle (TRANSIENT) → @always → routing (TRANSIENT, guarded @always)
 *   → [IsEligibleGuard=true]  → processing (DELEGATION, job: ProcessJob)
 *      → @done → reviewing (INTERACTIVE)
 *         → APPROVE → approved (FINAL)
 *         → REJECT  → rejected (FINAL)
 *         → START_PARALLEL → parallel_check
 *         → DELEGATE → delegating
 *      → @fail    → failed (FINAL)
 *      → @timeout → timed_out (FINAL)
 *   → [IsEligibleGuard=false] → blocked (FINAL)
 *
 * delegating (DELEGATION, machine: ScenarioTestChildMachine)
 *   → @done       → delegation_complete (FINAL)
 *   → @done.error → delegation_error (FINAL)
 *   → @fail       → delegation_failed (FINAL)
 *
 * parallel_check (PARALLEL)
 *   → region_a: checking_a → a_done (FINAL)
 *   → region_b: checking_b → b_done (FINAL)
 *   → @done → [IsValidGuard] → all_checked (INTERACTIVE)
 *   → @fail → check_failed (FINAL)
 *   → SKIP_CHECK → skipped (on-transition on parallel state)
 */
class ScenarioTestMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'scenario_test',
                'initial' => 'idle',
                'context' => ScenarioTestContext::class,
                'states'  => [
                    'idle' => [
                        'on' => [
                            '@always' => 'routing',
                        ],
                    ],
                    'routing' => [
                        'on' => [
                            '@always' => [
                                [
                                    'guards' => IsEligibleGuard::class,
                                    'target' => 'processing',
                                ],
                                [
                                    'target' => 'blocked',
                                ],
                            ],
                        ],
                    ],
                    'processing' => [
                        'job'   => ProcessJob::class,
                        'entry' => ProcessAction::class,
                        '@done' => 'reviewing',
                        '@fail' => 'failed',
                        '@timeout' => [
                            'target'  => 'timed_out',
                            'timeout' => 5000,
                        ],
                    ],
                    'reviewing' => [
                        'on' => [
                            'APPROVE'        => 'approved',
                            'REJECT'         => 'rejected',
                            'START_PARALLEL' => 'parallel_check',
                            'DELEGATE'       => 'delegating',
                        ],
                    ],
                    'delegating' => [
                        'machine' => ScenarioTestChildMachine::class,
                        '@done'   => 'delegation_complete',
                        '@done.error' => 'delegation_error',
                        '@fail'   => 'delegation_failed',
                    ],
                    'parallel_check' => [
                        'type'  => 'parallel',
                        'on'    => [
                            'SKIP_CHECK' => 'skipped',
                        ],
                        '@done' => [
                            'target' => 'all_checked',
                            'guards' => IsValidGuard::class,
                        ],
                        '@fail' => 'check_failed',
                        'states' => [
                            'region_a' => [
                                'initial' => 'checking_a',
                                'states'  => [
                                    'checking_a' => [
                                        'on' => [
                                            '@always' => 'a_done',
                                        ],
                                    ],
                                    'a_done' => ['type' => 'final'],
                                ],
                            ],
                            'region_b' => [
                                'initial' => 'checking_b',
                                'states'  => [
                                    'checking_b' => [
                                        'on' => [
                                            '@always' => 'b_done',
                                        ],
                                    ],
                                    'b_done' => ['type' => 'final'],
                                ],
                            ],
                        ],
                    ],
                    'approved'            => ['type' => 'final'],
                    'rejected'            => ['type' => 'final'],
                    'blocked'             => ['type' => 'final'],
                    'failed'              => ['type' => 'final'],
                    'timed_out'           => ['type' => 'final'],
                    'delegation_complete' => ['type' => 'final'],
                    'delegation_error'    => ['type' => 'final'],
                    'delegation_failed'   => ['type' => 'final'],
                    'all_checked'         => [
                        'on' => [
                            'FINISH' => 'approved',
                        ],
                    ],
                    'check_failed'        => ['type' => 'final'],
                    'skipped'             => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'APPROVE' => ApproveEvent::class,
                    'REJECT'  => RejectEvent::class,
                ],
            ],
            endpoints: [
                'reviewing' => [
                    'approve' => [
                        'uri'    => '/approve',
                        'method' => 'POST',
                        'action' => 'APPROVE',
                    ],
                    'reject' => [
                        'uri'    => '/reject',
                        'method' => 'POST',
                        'action' => 'REJECT',
                    ],
                ],
            ],
        );
    }
}
