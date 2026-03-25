<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;

// region Bead 1: entry-action-throws
// Entry actions execute AFTER state transition (setCurrentStateDefinition mutates State in-place),
// so when an entry action throws, the in-memory State is already at the target state.
// However, persist() is NOT called — no corrupt state reaches the database.

test('entry action that throws leaves in-memory state at target because entry runs post-transition', function (): void {
    $machine = Machine::create([
        'config' => [
            'initial' => 'idle',
            'context' => [
                'counter' => 0,
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'GO' => 'active',
                    ],
                ],
                'active' => [
                    'entry' => 'throwingEntryAction',
                ],
            ],
        ],
        'behavior' => [
            'actions' => [
                'throwingEntryAction' => function (): void {
                    throw new RuntimeException('Entry action exploded');
                },
            ],
        ],
    ]);

    try {
        $machine->send(['type' => 'GO']);
    } catch (RuntimeException) {
        // Expected
    }

    // State object was mutated in-place before entry action ran,
    // so the in-memory state is at the target. persist() was NOT called.
    expect($machine->state->matches('active'))->toBeTrue()
        ->and($machine->state->context->get('counter'))->toBe(0);
});

test('entry action that throws propagates the exception', function (): void {
    $machine = Machine::create([
        'config' => [
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => [
                        'GO' => 'active',
                    ],
                ],
                'active' => [
                    'entry' => 'throwingEntryAction',
                ],
            ],
        ],
        'behavior' => [
            'actions' => [
                'throwingEntryAction' => function (): void {
                    throw new RuntimeException('Entry action exploded');
                },
            ],
        ],
    ]);

    expect(fn () => $machine->send(['type' => 'GO']))
        ->toThrow(RuntimeException::class, 'Entry action exploded');
});

// endregion

// region Bead 2: exit-action-throws
// Exit actions execute BEFORE state transition, so when an exit action throws,
// the state remains at the source state — no corruption.

test('exit action that throws keeps machine in source state', function (): void {
    $machine = Machine::create([
        'config' => [
            'initial' => 'idle',
            'context' => [
                'counter' => 0,
            ],
            'states' => [
                'idle' => [
                    'exit' => 'throwingExitAction',
                    'on'   => [
                        'GO' => 'active',
                    ],
                ],
                'active' => [],
            ],
        ],
        'behavior' => [
            'actions' => [
                'throwingExitAction' => function (): void {
                    throw new RuntimeException('Exit action exploded');
                },
            ],
        ],
    ]);

    $stateBefore = $machine->state;

    try {
        $machine->send(['type' => 'GO']);
    } catch (RuntimeException) {
        // Expected
    }

    // Exit runs before setCurrentStateDefinition — state unchanged
    expect($machine->state->matches('idle'))->toBeTrue()
        ->and($machine->state->context->get('counter'))->toBe(0)
        ->and($machine->state->value)->toBe($stateBefore->value);
});

test('exit action that throws propagates the exception', function (): void {
    $machine = Machine::create([
        'config' => [
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'exit' => 'throwingExitAction',
                    'on'   => [
                        'GO' => 'active',
                    ],
                ],
                'active' => [],
            ],
        ],
        'behavior' => [
            'actions' => [
                'throwingExitAction' => function (): void {
                    throw new RuntimeException('Exit action exploded');
                },
            ],
        ],
    ]);

    expect(fn () => $machine->send(['type' => 'GO']))
        ->toThrow(RuntimeException::class, 'Exit action exploded');
});

// endregion

// region Bead 3: transition-action-throws
// Transition actions execute BEFORE exit actions and state change,
// so when a transition action throws, the state remains at the source — no corruption.

test('transition action that throws keeps machine in source state', function (): void {
    $machine = Machine::create([
        'config' => [
            'initial' => 'idle',
            'context' => [
                'counter' => 0,
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'GO' => [
                            'target'  => 'active',
                            'actions' => 'throwingTransitionAction',
                        ],
                    ],
                ],
                'active' => [],
            ],
        ],
        'behavior' => [
            'actions' => [
                'throwingTransitionAction' => function (): void {
                    throw new RuntimeException('Transition action exploded');
                },
            ],
        ],
    ]);

    $stateBefore = $machine->state;

    try {
        $machine->send(['type' => 'GO']);
    } catch (RuntimeException) {
        // Expected
    }

    // Transition actions run before state change — state unchanged
    expect($machine->state->matches('idle'))->toBeTrue()
        ->and($machine->state->context->get('counter'))->toBe(0)
        ->and($machine->state->value)->toBe($stateBefore->value);
});

test('transition action that throws propagates the exception', function (): void {
    $machine = Machine::create([
        'config' => [
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => [
                        'GO' => [
                            'target'  => 'active',
                            'actions' => 'throwingTransitionAction',
                        ],
                    ],
                ],
                'active' => [],
            ],
        ],
        'behavior' => [
            'actions' => [
                'throwingTransitionAction' => function (): void {
                    throw new RuntimeException('Transition action exploded');
                },
            ],
        ],
    ]);

    expect(fn () => $machine->send(['type' => 'GO']))
        ->toThrow(RuntimeException::class, 'Transition action exploded');
});

test('second transition action throwing after first mutated context leaves machine in source state', function (): void {
    $machine = Machine::create([
        'config' => [
            'initial' => 'idle',
            'context' => [
                'stepOne' => false,
                'stepTwo' => false,
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'GO' => [
                            'target'  => 'active',
                            'actions' => ['stepOneAction', 'throwingStepTwoAction'],
                        ],
                    ],
                ],
                'active' => [],
            ],
        ],
        'behavior' => [
            'actions' => [
                'stepOneAction' => function (ContextManager $context): void {
                    $context->set('stepOne', true);
                },
                'throwingStepTwoAction' => function (): void {
                    throw new RuntimeException('Step two exploded');
                },
            ],
        ],
    ]);

    try {
        $machine->send(['type' => 'GO']);
    } catch (RuntimeException) {
        // Expected
    }

    // Machine stays in source state — transition actions run before state change
    expect($machine->state->matches('idle'))->toBeTrue();
});

// endregion

// region Bead 4: guard-throws-exception
// DUPLICATE — already fully covered by GuardErrorBoundaryTest.php:
//   - 'guard that throws RuntimeException propagates the exception'
//   - 'guard that throws leaves machine state unchanged'
//   - 'guard that throws does not corrupt context even with preceding actions'
// Closed as covered.
// endregion
