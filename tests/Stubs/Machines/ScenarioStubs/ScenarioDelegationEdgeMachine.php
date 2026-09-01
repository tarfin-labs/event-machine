<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Jobs\ProcessJob;

/**
 * Delegation states whose successors the resolver does and does not follow.
 *
 * `fire_forget` carries an ordinary `on:` transition and none of the four delegation keys, so
 * the resolver dead-ends there. `fail_only` carries `@fail` alongside an ordinary transition,
 * so it is walkable — but by `@fail` alone.
 *
 * Both `on:` transitions target `after_edge`, which is therefore unreachable through either
 * state as far as the resolver is concerned. That is the resolver's graph, not the runtime's
 * behaviour; the divergence is catalogued in
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
                        ],
                    ],
                    'fire_forget' => [
                        'job'    => ProcessJob::class,
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
