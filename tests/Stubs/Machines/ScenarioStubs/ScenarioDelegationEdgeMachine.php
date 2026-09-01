<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Jobs\ProcessJob;

/**
 * Delegation states whose successors the resolver does and does not follow.
 *
 *   fire_forget  job + target + an ordinary `on:`   none of the four keys, so it dead-ends
 *   fail_only    job + @done + @fail + an `on:`     walkable by BOTH delegation keys
 *   fail_pure    job + @fail + target               walkable by @fail with no @done present
 *
 * `target` is the fire-and-forget destination the ENGINE takes; it is not a transition, and the
 * resolver does not follow it. Neither are the ordinary `on:` transitions. That is why
 * `after_edge` is unreachable through all three states even though every one of them names it.
 *
 * fail_pure carries the discriminating case: a reader could reasonably believe the resolver
 * collects `@fail` only alongside `@done`. It has to carry `target` as well, because the engine
 * rejects a `job` state with neither `@done` nor `target`
 * (InvalidStateConfigException::jobRequiresDoneOrTarget) — `@fail` entirely on its own is not a
 * definition that can be written, so the closest constructible shape is the one used here.
 *
 * The refusal to follow ordinary `on:` transitions out of a delegation state is the resolver's
 * graph, not the runtime's behaviour; the divergence is catalogued in
 * spec/draft-scenario-resolver-runtime-divergences.md and deliberately not fixed here.
 */
class ScenarioDelegationEdgeMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'scenario_delegation_edge',
                'initial' => 'root',
                'states'  => [
                    'root' => [
                        'on' => [
                            'FF'      => 'fire_forget',
                            'PARTIAL' => 'fail_only',
                            'PURE'    => 'fail_pure',
                        ],
                    ],
                    'fire_forget' => [
                        'job'    => ProcessJob::class,
                        'target' => 'after_edge',
                        'on'     => [
                            'CONTINUE' => 'after_edge',
                        ],
                    ],
                    'fail_pure' => [
                        'job'    => ProcessJob::class,
                        '@fail'  => 'failed_out',
                        'target' => 'after_edge',
                    ],
                    'fail_only' => [
                        'job'   => ProcessJob::class,
                        '@done' => 'recovered',
                        '@fail' => 'failed_out',
                        'on'    => [
                            'SKIP' => 'after_edge',
                        ],
                    ],
                    'failed_out' => ['type' => 'final'],
                    'recovered'  => ['type' => 'final'],
                    'after_edge' => ['type' => 'final'],
                ],
            ],
        );
    }
}
