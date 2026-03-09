<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;

// ---------------------------------------------------------------------------
// Root-level `on` event while in parallel state
// ---------------------------------------------------------------------------

it('handles root-level on event that escapes the parallel state', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'test_root_escape',
        'initial' => 'processing',

        // Root-level event — should exit parallel and go to expired
        'on' => [
            'EXPIRED' => 'expired',
        ],

        'states' => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 'done',
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => ['on' => ['DONE_A' => 'finished']],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => ['on' => ['DONE_B' => 'finished']],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'done'    => ['type' => 'final'],
            'expired' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    // Both regions active
    expect($state->value)->toBe([
        'test_root_escape.processing.region_a.working',
        'test_root_escape.processing.region_b.working',
    ]);

    // Advance one region
    $state = $definition->transition(['type' => 'DONE_A'], $state);
    expect($state->value)->toBe([
        'test_root_escape.processing.region_a.finished',
        'test_root_escape.processing.region_b.working',
    ]);

    // Fire root-level EXPIRED — should exit the entire parallel state
    $state = $definition->transition(['type' => 'EXPIRED'], $state);

    expect($state->value)->toBe(['test_root_escape.expired']);
});

// ---------------------------------------------------------------------------
// Parallel-state-level `on` event that escapes the parallel state
// ---------------------------------------------------------------------------

it('handles parallel-state on event that escapes to a sibling state', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'test_parallel_escape',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'  => 'parallel',
                '@done' => 'done',
                'on'    => [
                    'CANCEL' => 'cancelled',
                ],
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => ['on' => ['DONE_A' => 'finished']],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => ['on' => ['DONE_B' => 'finished']],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'done'      => ['type' => 'final'],
            'cancelled' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    // Advance one region
    $state = $definition->transition(['type' => 'DONE_A'], $state);
    expect($state->value)->toBe([
        'test_parallel_escape.processing.region_a.finished',
        'test_parallel_escape.processing.region_b.working',
    ]);

    // Fire CANCEL on the parallel state itself
    $state = $definition->transition(['type' => 'CANCEL'], $state);

    expect($state->value)->toBe(['test_parallel_escape.cancelled']);
});

// ---------------------------------------------------------------------------
// Deduplication: actions and guards should NOT fire multiple times
// ---------------------------------------------------------------------------

it('does not duplicate transition actions for ancestor-level events', function (): void {
    $counter = new class() {
        public int $count = 0;
    };

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'test_dedup',
            'initial' => 'processing',
            'on'      => [
                'ABORT' => [
                    'target'  => 'aborted',
                    'actions' => 'incrementAction',
                ],
            ],
            'states' => [
                'processing' => [
                    'type'   => 'parallel',
                    '@done'  => 'done',
                    'states' => [
                        'region_a' => [
                            'initial' => 'working',
                            'states'  => [
                                'working'  => ['on' => ['DONE_A' => 'finished']],
                                'finished' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'working',
                            'states'  => [
                                'working'  => ['on' => ['DONE_B' => 'finished']],
                                'finished' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'done'    => ['type' => 'final'],
                'aborted' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'incrementAction' => function () use ($counter): void {
                    $counter->count++;
                },
            ],
        ]
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'ABORT'], $state);

    // Action must fire exactly once, not once per region
    expect($counter->count)->toBe(1);
    expect($state->value)->toBe(['test_dedup.aborted']);
});

// ---------------------------------------------------------------------------
// Root-level on event with guard
// ---------------------------------------------------------------------------

it('evaluates guards on root-level escape transitions', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'test_guarded_escape',
            'initial' => 'processing',
            'on'      => [
                'TIMEOUT' => [
                    'target' => 'timed_out',
                    'guards' => 'canTimeOutGuard',
                ],
            ],
            'states' => [
                'processing' => [
                    'type'   => 'parallel',
                    '@done'  => 'done',
                    'states' => [
                        'region_a' => [
                            'initial' => 'working',
                            'states'  => [
                                'working'  => ['on' => ['DONE_A' => 'finished']],
                                'finished' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'working',
                            'states'  => [
                                'working'  => ['on' => ['DONE_B' => 'finished']],
                                'finished' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'done'      => ['type' => 'final'],
                'timed_out' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'guards' => [
                'canTimeOutGuard' => fn () => true,
            ],
        ]
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'TIMEOUT'], $state);

    expect($state->value)->toBe(['test_guarded_escape.timed_out']);
});

// ---------------------------------------------------------------------------
// Normal region events still work after the fix
// ---------------------------------------------------------------------------

it('still handles normal within-region transitions correctly', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'test_region_normal',
        'initial' => 'processing',
        'on'      => [
            'EXPIRED' => 'expired',
        ],
        'states' => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 'done',
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => ['on' => ['DONE_A' => 'finished']],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => ['on' => ['DONE_B' => 'finished']],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'done'    => ['type' => 'final'],
            'expired' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    // Normal region transitions still work
    $state = $definition->transition(['type' => 'DONE_A'], $state);
    expect($state->value)->toBe([
        'test_region_normal.processing.region_a.finished',
        'test_region_normal.processing.region_b.working',
    ]);

    $state = $definition->transition(['type' => 'DONE_B'], $state);

    // @done fires → transitions to done
    expect($state->value)->toBe(['test_region_normal.done']);
});

// ---------------------------------------------------------------------------
// Exit actions fire on all regions during escape
// ---------------------------------------------------------------------------

it('runs exit actions on all active leaf states during escape', function (): void {
    $exits = new class() {
        public array $exited = [];
    };

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'test_exit_actions',
            'initial' => 'processing',
            'on'      => [
                'ABORT' => 'aborted',
            ],
            'states' => [
                'processing' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'exit' => 'exitRegionAAction',
                                ],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'exit' => 'exitRegionBAction',
                                ],
                            ],
                        ],
                    ],
                ],
                'aborted' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'exitRegionAAction' => function () use ($exits): void {
                    $exits->exited[] = 'region_a';
                },
                'exitRegionBAction' => function () use ($exits): void {
                    $exits->exited[] = 'region_b';
                },
            ],
        ]
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'ABORT'], $state);

    expect($state->value)->toBe(['test_exit_actions.aborted']);
    expect($exits->exited)->toContain('region_a');
    expect($exits->exited)->toContain('region_b');
    expect($exits->exited)->toHaveCount(2);
});
