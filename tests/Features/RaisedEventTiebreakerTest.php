<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\NoTransitionDefinitionFoundException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\RaisedEventTiebreaker\Actions\RaiseTwoEventsAction;

test('first raised event exits state, second raised event throws when new state does not handle it', function (): void {
    // Apache Commons SCXML macrostep semantics:
    // When an entry action raises two events (EVENT_1 then EVENT_2),
    // EVENT_1 is dequeued first, causes A->B transition.
    // EVENT_2 is then dequeued but machine is now in B, not A.
    // Since B doesn't handle EVENT_2, a NoTransitionDefinitionFoundException is thrown
    // confirming EVENT_2 is evaluated against B (not A).

    $machine = MachineDefinition::define(
        config: [
            'id'      => 'tiebreaker',
            'initial' => 'idle',
            'context' => [
                'trace' => [],
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'GO' => 'A',
                    ],
                ],
                'A' => [
                    'entry' => RaiseTwoEventsAction::class,
                    'on'    => [
                        'EVENT_1' => [
                            'target'  => 'B',
                            'actions' => 'logEvent1TransitionAction',
                        ],
                        'EVENT_2' => [
                            'target'  => 'C',
                            'actions' => 'logEvent2TransitionAction',
                        ],
                    ],
                ],
                'B' => [
                    'entry' => 'logEntryBAction',
                    // B does NOT handle EVENT_2 — exception thrown (event evaluated against B, not A)
                ],
                'C' => [
                    'entry' => 'logEntryCAction',
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'logEvent1TransitionAction' => function (ContextManager $context): void {
                    $trace   = $context->get('trace');
                    $trace[] = 'transition_A_to_B_via_EVENT_1';
                    $context->set('trace', $trace);
                },
                'logEvent2TransitionAction' => function (ContextManager $context): void {
                    $trace   = $context->get('trace');
                    $trace[] = 'transition_A_to_C_via_EVENT_2';
                    $context->set('trace', $trace);
                },
                'logEntryBAction' => function (ContextManager $context): void {
                    $trace   = $context->get('trace');
                    $trace[] = 'B_entry';
                    $context->set('trace', $trace);
                },
                'logEntryCAction' => function (ContextManager $context): void {
                    $trace   = $context->get('trace');
                    $trace[] = 'C_entry';
                    $context->set('trace', $trace);
                },
            ],
        ],
    );

    // EVENT_1 transitions A->B, then EVENT_2 is evaluated against B (not A).
    // B doesn't handle EVENT_2, so an exception is thrown — proving the
    // tiebreaker: first raised event exits the state, second is NOT retried against A.
    expect(fn () => $machine->transition(event: ['type' => 'GO']))
        ->toThrow(NoTransitionDefinitionFoundException::class, 'EVENT_2');
});

test('second raised event is processed in new state when new state handles it', function (): void {
    // Variant: B DOES handle EVENT_2, so it transitions B->D
    // This proves EVENT_2 is processed in the new state (B), not the original (A)

    $machine = MachineDefinition::define(
        config: [
            'id'      => 'tiebreaker_handled',
            'initial' => 'idle',
            'context' => [
                'trace' => [],
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'GO' => 'A',
                    ],
                ],
                'A' => [
                    'entry' => RaiseTwoEventsAction::class,
                    'on'    => [
                        'EVENT_1' => [
                            'target'  => 'B',
                            'actions' => 'logEvent1TransitionAction',
                        ],
                        'EVENT_2' => [
                            'target'  => 'C',
                            'actions' => 'logEvent2FromAAction',
                        ],
                    ],
                ],
                'B' => [
                    'entry' => 'logEntryBAction',
                    'on'    => [
                        'EVENT_2' => [
                            'target'  => 'D',
                            'actions' => 'logEvent2FromBAction',
                        ],
                    ],
                ],
                'C' => [
                    'entry' => 'logEntryCAction',
                ],
                'D' => [
                    'entry' => 'logEntryDAction',
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'logEvent1TransitionAction' => function (ContextManager $context): void {
                    $trace   = $context->get('trace');
                    $trace[] = 'transition_A_to_B_via_EVENT_1';
                    $context->set('trace', $trace);
                },
                'logEvent2FromAAction' => function (ContextManager $context): void {
                    $trace   = $context->get('trace');
                    $trace[] = 'transition_A_to_C_via_EVENT_2';
                    $context->set('trace', $trace);
                },
                'logEvent2FromBAction' => function (ContextManager $context): void {
                    $trace   = $context->get('trace');
                    $trace[] = 'transition_B_to_D_via_EVENT_2';
                    $context->set('trace', $trace);
                },
                'logEntryBAction' => function (ContextManager $context): void {
                    $trace   = $context->get('trace');
                    $trace[] = 'B_entry';
                    $context->set('trace', $trace);
                },
                'logEntryCAction' => function (ContextManager $context): void {
                    $trace   = $context->get('trace');
                    $trace[] = 'C_entry';
                    $context->set('trace', $trace);
                },
                'logEntryDAction' => function (ContextManager $context): void {
                    $trace   = $context->get('trace');
                    $trace[] = 'D_entry';
                    $context->set('trace', $trace);
                },
            ],
        ],
    );

    $state = $machine->transition(event: ['type' => 'GO']);

    // Machine should end up in D (not C), because:
    // 1. A's entry raises EVENT_1 and EVENT_2
    // 2. EVENT_1 processed -> A transitions to B
    // 3. EVENT_2 processed -> machine is now in B, B handles EVENT_2 -> transitions to D
    // C is never reached (EVENT_2 is not processed against A's transitions)
    expect($state->matches('D'))->toBeTrue();

    expect($state->context->get('trace'))->toBe([
        'A_entry_raise_EVENT_1',
        'A_entry_raise_EVENT_2',
        'transition_A_to_B_via_EVENT_1',
        'B_entry',
        'transition_B_to_D_via_EVENT_2',
        'D_entry',
    ]);

    // C should never have been entered — EVENT_2 was NOT evaluated against A
    expect($state->context->get('trace'))->not->toContain('C_entry');
    expect($state->context->get('trace'))->not->toContain('transition_A_to_C_via_EVENT_2');
});
