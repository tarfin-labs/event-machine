<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * The worked example from the weighted-path-resolution spec, plus the shapes its
 * ordering cases need.
 *
 * One trigger — pending --SUBMIT--> eligibility — reaches `approved` by five routes
 * that diverge at `eligibility`, so a single resolveAll() call returns all of them:
 *
 *   manual    eligibility(1) → manual_check(1)               → review(1) → approved(0)  = 3, 4 steps
 *   alt       eligibility(1) → alt_check(1)                  → review(1) → approved(0)  = 3, 4 steps
 *   slow      eligibility(1) → slow_hop(0) → other_check(1)  → review(1) → approved(0)  = 3, 5 steps
 *   long      eligibility(1) → long_a(1) → long_b(1)         → review(1) → approved(0)  = 4, 5 steps
 *   parallel  eligibility(1) → verification(5)               → review(1) → approved(0)  = 7, 4 steps
 *
 * `manual` and `alt` are tied on both sort keys; `slow` ties them on cost and loses on
 * length; `parallel` is the expensive equal-length route the spec's §1 example is about.
 * The two regions inside `verification` are self-contained, so descending into one is a
 * dead end and contributes no extra route to `approved`.
 */
class ScenarioOrderingMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'scenario_ordering',
                'initial' => 'pending',
                'states'  => [
                    'pending' => [
                        'on' => [
                            'SUBMIT' => 'eligibility',
                        ],
                    ],
                    'eligibility' => [
                        'on' => [
                            'MANUAL' => 'manual_check',
                            'ALT'    => 'alt_check',
                            'SLOW'   => 'slow_hop',
                            'LONG'   => 'long_a',
                            'VERIFY' => 'verification',
                        ],
                    ],
                    'manual_check' => [
                        'on' => [
                            'CHECKED' => 'review',
                        ],
                    ],
                    'alt_check' => [
                        'on' => [
                            'CHECKED_ALT' => 'review',
                        ],
                    ],
                    'slow_hop' => [
                        'on' => [
                            '@always' => 'other_check',
                        ],
                    ],
                    'other_check' => [
                        'on' => [
                            'CONFIRM' => 'review',
                        ],
                    ],
                    'long_a' => [
                        'on' => [
                            'NEXT' => 'long_b',
                        ],
                    ],
                    'long_b' => [
                        'on' => [
                            'NEXT_AGAIN' => 'review',
                        ],
                    ],
                    'verification' => [
                        'type'   => 'parallel',
                        '@done'  => 'review',
                        'states' => [
                            'region_a' => [
                                'initial' => 'checking_a',
                                'states'  => [
                                    'checking_a' => [
                                        'on' => [
                                            'A_OK' => 'a_done',
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
                                            'B_OK' => 'b_done',
                                        ],
                                    ],
                                    'b_done' => ['type' => 'final'],
                                ],
                            ],
                        ],
                    ],
                    'review' => [
                        'on' => [
                            'APPROVE' => 'approved',
                        ],
                    ],
                    'approved' => ['type' => 'final'],
                ],
            ],
        );
    }
}
