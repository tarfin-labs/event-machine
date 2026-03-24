<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Compound;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Tests combined child-over-parent priority and document-order tiebreaker.
 *
 * Parent state 'parent' has two guarded transitions on TRIGGER:
 *   1st -> parent_target_x (alwaysTrueGuard)
 *   2nd -> parent_target_y (alwaysTrueGuard)
 *
 * Child 'child_with_handler' handles TRIGGER -> child_target.
 * Child 'child_without_handler' does NOT handle TRIGGER.
 *
 * Expected:
 *  - At parent.child_with_handler: TRIGGER -> child_target (child wins)
 *  - At parent.child_without_handler: TRIGGER -> parent_target_x (first-match wins)
 */
class ChildOverParentDocOrderMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'child_over_parent_doc_order',
                'initial' => 'parent',
                'states'  => [
                    'parent' => [
                        'initial' => 'child_with_handler',
                        'on'      => [
                            'TRIGGER' => [
                                [
                                    'target' => 'parent_target_x',
                                    'guards' => 'alwaysTrueGuardA',
                                ],
                                [
                                    'target' => 'parent_target_y',
                                    'guards' => 'alwaysTrueGuardB',
                                ],
                            ],
                        ],
                        'states' => [
                            'child_with_handler' => [
                                'on' => [
                                    'TRIGGER' => 'child_target',
                                ],
                            ],
                            'child_without_handler' => [],
                            'child_target'          => [],
                        ],
                    ],
                    'parent_target_x' => [],
                    'parent_target_y' => [],
                ],
            ],
            behavior: [
                'guards' => [
                    'alwaysTrueGuardA' => fn (): bool => true,
                    'alwaysTrueGuardB' => fn (): bool => true,
                ],
            ],
        );
    }
}
