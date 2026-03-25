<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\LocalQA;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Machine that accepts 4 distinct events in sequence (choir pattern).
 *
 * Each event advances to the next state. All 4 must be processed for
 * the machine to reach the 'completed' final state.
 *
 * idle → NOTE_A(A_SING) → NOTE_B(B_SING) → NOTE_C(C_SING) → NOTE_D(D_SING) → completed
 */
class BurstChoirMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'burst_choir',
                'initial' => 'idle',
                'context' => [
                    'notes_sung' => [],
                ],
                'states' => [
                    'idle' => [
                        'on' => [
                            'A_SING' => [
                                'target'  => 'note_a',
                                'actions' => 'recordNoteAction',
                            ],
                        ],
                    ],
                    'note_a' => [
                        'on' => [
                            'B_SING' => [
                                'target'  => 'note_b',
                                'actions' => 'recordNoteAction',
                            ],
                        ],
                    ],
                    'note_b' => [
                        'on' => [
                            'C_SING' => [
                                'target'  => 'note_c',
                                'actions' => 'recordNoteAction',
                            ],
                        ],
                    ],
                    'note_c' => [
                        'on' => [
                            'D_SING' => [
                                'target'  => 'completed',
                                'actions' => 'recordNoteAction',
                            ],
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'recordNoteAction' => function (ContextManager $context, EventBehavior $event): void {
                        $notes   = $context->get('notes_sung');
                        $notes[] = $event->type;
                        $context->set('notes_sung', $notes);
                    },
                ],
            ],
        );
    }
}
