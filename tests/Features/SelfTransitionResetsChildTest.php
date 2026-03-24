<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;

// XState #1885 — External self-transition on a compound state must reset
// the child to its initial state, not leave it wherever it was.

test('External self-transition on compound state resets child to initial', function (): void {
    $machine = Machine::create([
        'config' => [
            'id'      => 'doc',
            'initial' => 'editor',
            'states'  => [
                'editor' => [
                    'initial' => 'draft',
                    'on'      => [
                        'RESET' => 'editor', // external self-transition
                    ],
                    'states' => [
                        'draft' => [
                            'on' => [
                                'ADVANCE' => 'reviewing',
                            ],
                        ],
                        'reviewing' => [],
                    ],
                ],
            ],
        ],
    ]);

    $machine->start();

    // Should start at editor.draft
    expect($machine->state->value)->toBe(['doc.editor.draft']);

    // Advance to editor.reviewing
    $machine->send(['type' => 'ADVANCE']);
    expect($machine->state->value)->toBe(['doc.editor.reviewing']);

    // External self-transition on 'editor' must reset child to initial (draft)
    $machine->send(['type' => 'RESET']);
    expect($machine->state->value)->toBe(['doc.editor.draft']);
});
