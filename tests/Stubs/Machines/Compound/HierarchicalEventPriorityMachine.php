<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Compound;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * A compound state machine for testing hierarchical event priority.
 *
 * Parent state 'form' handles SUBMIT -> 'review'.
 * Child state 'editing' (inside 'form') also handles SUBMIT -> 'validating'.
 * Child state 'waiting' (inside 'form') does NOT handle SUBMIT.
 *
 * Expected behavior (per statechart semantics):
 *  - At form.editing, SUBMIT -> validating (child wins)
 *  - At form.waiting, SUBMIT -> review (parent fallback)
 */
class HierarchicalEventPriorityMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'hierarchical_event_priority',
                'initial' => 'form',
                'states'  => [
                    'form' => [
                        'initial' => 'editing',
                        'on'      => [
                            'SUBMIT' => 'review',
                        ],
                        'states' => [
                            'editing' => [
                                'on' => [
                                    'SUBMIT' => 'validating',
                                ],
                            ],
                            'waiting'    => [],
                            'validating' => [],
                        ],
                    ],
                    'review' => [],
                ],
            ],
        );
    }
}
