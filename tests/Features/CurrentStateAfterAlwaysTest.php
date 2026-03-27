<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;

// ═══════════════════════════════════════════════════════════════
//  Bead 3: machine_current_states table shows final resting
//  state after @always chain. Machine with @always A→B→C.
//  Verify MachineCurrentState record shows C, not A or B.
//
//  NOTE: These tests INTENTIONALLY assert on MachineCurrentState
//  (not restored machine) because they verify the current_state
//  table is correctly updated after @always chains.
// ═══════════════════════════════════════════════════════════════

it('machine_current_states shows final resting state after @always chain', function (): void {
    $machine = Machine::create([
        'config' => [
            'id'      => 'always_chain_cs',
            'initial' => 'idle',
            'context' => [
                'chainTrace' => [],
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'START' => 'step_a',
                    ],
                ],
                'step_a' => [
                    'entry' => 'traceAAction',
                    'on'    => [
                        '@always' => 'step_b',
                    ],
                ],
                'step_b' => [
                    'entry' => 'traceBAction',
                    'on'    => [
                        '@always' => 'step_c',
                    ],
                ],
                'step_c' => [
                    'entry' => 'traceCAction',
                ],
            ],
        ],
        'behavior' => [
            'actions' => [
                'traceAAction' => function (ContextManager $context): void {
                    $trace   = $context->get('chainTrace');
                    $trace[] = 'A';
                    $context->set('chainTrace', $trace);
                },
                'traceBAction' => function (ContextManager $context): void {
                    $trace   = $context->get('chainTrace');
                    $trace[] = 'B';
                    $context->set('chainTrace', $trace);
                },
                'traceCAction' => function (ContextManager $context): void {
                    $trace   = $context->get('chainTrace');
                    $trace[] = 'C';
                    $context->set('chainTrace', $trace);
                },
            ],
        ],
    ]);

    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->send(['type' => 'START']);

    // Machine should be in step_c (final state after @always chain)
    expect($machine->state->value)->toBe(['always_chain_cs.step_c']);

    // Verify chain_trace shows all states were visited in order
    expect($machine->state->context->get('chainTrace'))->toBe(['A', 'B', 'C']);

    // The critical assertion: MachineCurrentState record shows final resting state
    $currentState = MachineCurrentState::where('root_event_id', $rootEventId)->first();

    expect($currentState)->not->toBeNull()
        ->and($currentState->state_id)->toBe('always_chain_cs.step_c')
        ->and($currentState->state_id)->not->toBe('always_chain_cs.step_a')
        ->and($currentState->state_id)->not->toBe('always_chain_cs.step_b');
});

it('machine_current_states shows final state after guarded @always chain', function (): void {
    $machine = Machine::create([
        'config' => [
            'id'      => 'guarded_always_cs',
            'initial' => 'idle',
            'context' => [
                'score' => 0,
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'EVALUATE' => [
                            'target'  => 'checking',
                            'actions' => 'setScoreAction',
                        ],
                    ],
                ],
                'checking' => [
                    'on' => [
                        '@always' => [
                            [
                                'target' => 'approved',
                                'guards' => 'isHighScoreGuard',
                            ],
                            [
                                'target' => 'rejected',
                            ],
                        ],
                    ],
                ],
                'approved' => [],
                'rejected' => [],
            ],
        ],
        'behavior' => [
            'actions' => [
                'setScoreAction' => function (ContextManager $context): void {
                    $context->set('score', 100);
                },
            ],
            'guards' => [
                'isHighScoreGuard' => function (ContextManager $context): bool {
                    return $context->get('score') >= 80;
                },
            ],
        ],
    ]);

    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->send(['type' => 'EVALUATE']);

    expect($machine->state->value)->toBe(['guarded_always_cs.approved']);

    // MachineCurrentState should show final state after guarded @always
    $currentState = MachineCurrentState::where('root_event_id', $rootEventId)->first();

    expect($currentState)->not->toBeNull()
        ->and($currentState->state_id)->toBe('guarded_always_cs.approved')
        ->and($currentState->state_id)->not->toBe('guarded_always_cs.checking');
});

it('machine_current_states has exactly one row for simple machine after @always chain', function (): void {
    $machine = Machine::create([
        'config' => [
            'id'      => 'always_single_row',
            'initial' => 'start',
            'context' => [],
            'states'  => [
                'start' => [
                    'on' => [
                        'GO' => 'hop',
                    ],
                ],
                'hop' => [
                    'on' => [
                        '@always' => 'skip',
                    ],
                ],
                'skip' => [
                    'on' => [
                        '@always' => 'jump',
                    ],
                ],
                'jump' => [],
            ],
        ],
    ]);

    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->send(['type' => 'GO']);

    // Should have exactly one current state record — not multiple from intermediate states
    $currentStates = MachineCurrentState::where('root_event_id', $rootEventId)->get();

    expect($currentStates)->toHaveCount(1)
        ->and($currentStates->first()->state_id)->toBe('always_single_row.jump');
});
