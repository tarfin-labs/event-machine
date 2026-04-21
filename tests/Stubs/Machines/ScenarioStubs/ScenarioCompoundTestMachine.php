<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Minimal machine for testing compound state traversal in ScenarioPathResolver.
 *
 * idle (TRANSIENT) → @always → phone_resolution (COMPOUND, initial: checking_phone_cache)
 *   checking_phone_cache (TRANSIENT) → @always → matching_phone (INTERACTIVE)
 *     → PHONE_SELECTED → phones_resolved (FINAL)
 *
 * Also includes nested compound test:
 * reviewing → NEST → outer (COMPOUND, initial: middle)
 *   middle (COMPOUND, initial: leaf_checking)
 *     leaf_checking (TRANSIENT) → @always → leaf_done (FINAL)
 */
class ScenarioCompoundTestMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'scenario_compound_test',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => [
                            '@always' => 'phone_resolution',
                        ],
                    ],
                    'phone_resolution' => [
                        'initial' => 'checking_phone_cache',
                        'states'  => [
                            'checking_phone_cache' => [
                                'on' => [
                                    '@always' => 'matching_phone',
                                ],
                            ],
                            'matching_phone' => [
                                'on' => [
                                    'PHONE_SELECTED' => 'phones_resolved',
                                ],
                            ],
                            'phones_resolved' => ['type' => 'final'],
                        ],
                    ],
                    'reviewing' => [
                        'on' => [
                            'NEST' => 'outer',
                        ],
                    ],
                    'outer' => [
                        'initial' => 'middle',
                        'states'  => [
                            'middle' => [
                                'initial' => 'leaf_checking',
                                'states'  => [
                                    'leaf_checking' => [
                                        'on' => [
                                            '@always' => 'leaf_done',
                                        ],
                                    ],
                                    'leaf_done' => ['type' => 'final'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        );
    }
}
