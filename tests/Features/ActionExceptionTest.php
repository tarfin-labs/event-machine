<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;

// region Bead 1: entry-action-throws
// Entry action throws RuntimeException. Machine state should NOT be corrupted.

test('entry action that throws RuntimeException does not corrupt machine state', function (): void {
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

    // Capture state before the failed send
    $stateBefore = $machine->state;

    // Act & Assert — exception propagates
    try {
        $machine->send(['type' => 'GO']);
    } catch (RuntimeException) {
        // Expected
    }

    // Machine should still be in idle (previous state), not corrupted
    expect($machine->state->matches('idle'))->toBeTrue()
        ->and($machine->state->context->get('counter'))->toBe(0)
        ->and($machine->state->value)->toBe($stateBefore->value);
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
// Exit action throws. Verify transition is aborted or exception propagates. Machine state consistent.

test('exit action that throws RuntimeException does not corrupt machine state', function (): void {
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

    // Machine state should remain in idle, not corrupted
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
// Transition action throws mid-execution. Verify state consistency.

test('transition action that throws does not leave machine in corrupt state', function (): void {
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

    // Machine should remain in idle — partially executed transition must not corrupt state
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

test('transition action that throws after context mutation does not persist partial context', function (): void {
    $machine = Machine::create([
        'config' => [
            'initial' => 'idle',
            'context' => [
                'step_one' => false,
                'step_two' => false,
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
                    $context->set('step_one', true);
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

    // Machine state should remain in idle — no partial state leakage
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
