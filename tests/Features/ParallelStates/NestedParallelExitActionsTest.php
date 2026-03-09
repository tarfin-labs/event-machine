<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;

// ---------------------------------------------------------------------------
// Nested parallel state should run exit on the parallel state itself
// when it completes via @done and transitions to a sibling state
// ---------------------------------------------------------------------------

it('runs exit action on the nested parallel state itself when @done fires', function (): void {
    $exits = new class() {
        public array $exited = [];
    };

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'test_nested_parallel_exit',
            'initial' => 'outer',
            'states'  => [
                'outer' => [
                    'type'   => 'parallel',
                    '@done'  => 'all_done',
                    'states' => [
                        'track_a' => [
                            'initial' => 'inner_parallel',
                            'states'  => [
                                'inner_parallel' => [
                                    'type'   => 'parallel',
                                    'exit'   => 'exitInnerParallelAction',
                                    '@done'  => 'inner_done',
                                    'states' => [
                                        'sub_1' => [
                                            'initial' => 'active',
                                            'states'  => [
                                                'active' => [
                                                    'on' => ['DONE_1' => 'finished'],
                                                ],
                                                'finished' => [
                                                    'type' => 'final',
                                                    'exit' => 'exitFinalSub1Action',
                                                ],
                                            ],
                                        ],
                                        'sub_2' => [
                                            'initial' => 'active',
                                            'states'  => [
                                                'active' => [
                                                    'on' => ['DONE_2' => 'finished'],
                                                ],
                                                'finished' => [
                                                    'type' => 'final',
                                                    'exit' => 'exitFinalSub2Action',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                'inner_done' => ['type' => 'final'],
                            ],
                        ],
                        'track_b' => [
                            'initial' => 'waiting',
                            'states'  => [
                                'waiting' => [
                                    'on' => ['DONE_B' => 'done'],
                                ],
                                'done' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'all_done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'exitInnerParallelAction' => function () use ($exits): void {
                    $exits->exited[] = 'inner_parallel';
                },
                'exitFinalSub1Action' => function () use ($exits): void {
                    $exits->exited[] = 'final_sub_1';
                },
                'exitFinalSub2Action' => function () use ($exits): void {
                    $exits->exited[] = 'final_sub_2';
                },
            ],
        ]
    );

    $state = $definition->getInitialState();

    $state = $definition->transition(['type' => 'DONE_1'], $state);
    $state = $definition->transition(['type' => 'DONE_2'], $state);

    // After @done fires, track_a should be at inner_done
    expect($state->value)->toContain('test_nested_parallel_exit.outer.track_a.inner_done');

    // Exit actions should have fired on:
    // - final leaf states (sub_1.finished, sub_2.finished) being removed from state values
    // - the inner_parallel state itself
    expect($exits->exited)->toContain('final_sub_1');
    expect($exits->exited)->toContain('final_sub_2');
    expect($exits->exited)->toContain('inner_parallel');
});
