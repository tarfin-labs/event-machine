<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/*
 * Parallel NOT done when only some regions are final.
 *
 * SCXML semantics: @done fires ONLY when ALL regions reach final states.
 * Completing 2 out of 3 regions must NOT trigger @done.
 */

test('parallel @done does NOT fire when only 2 of 3 regions are final', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'partial_completion',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 'all_done',
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => [
                                'on' => ['FINISH_A' => 'completed'],
                            ],
                            'completed' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => [
                                'on' => ['FINISH_B' => 'completed'],
                            ],
                            'completed' => ['type' => 'final'],
                        ],
                    ],
                    'region_c' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => [
                                'on' => ['FINISH_C' => 'completed'],
                            ],
                            'completed' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'all_done' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    // All three regions start in working
    expect($state->matches('processing.region_a.working'))->toBeTrue()
        ->and($state->matches('processing.region_b.working'))->toBeTrue()
        ->and($state->matches('processing.region_c.working'))->toBeTrue();

    // Complete region A
    $state = $definition->transition(['type' => 'FINISH_A'], $state);
    expect($state->matches('processing.region_a.completed'))->toBeTrue()
        ->and($state->matches('all_done'))->toBeFalse('1-of-3 final must not trigger @done');

    // Complete region B — still only 2 of 3
    $state = $definition->transition(['type' => 'FINISH_B'], $state);
    expect($state->matches('processing.region_a.completed'))->toBeTrue()
        ->and($state->matches('processing.region_b.completed'))->toBeTrue()
        ->and($state->matches('processing.region_c.working'))->toBeTrue()
        ->and($state->matches('all_done'))->toBeFalse('2-of-3 final must not trigger @done');

    // Verify we are still in the parallel state
    $currentStateIds = $state->value;
    foreach ($currentStateIds as $stateId) {
        expect($stateId)->toStartWith('partial_completion.processing.');
    }
});

test('parallel @done fires only when all 3 regions reach final', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'full_completion',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 'all_done',
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => [
                                'on' => ['FINISH_A' => 'completed'],
                            ],
                            'completed' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => [
                                'on' => ['FINISH_B' => 'completed'],
                            ],
                            'completed' => ['type' => 'final'],
                        ],
                    ],
                    'region_c' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => [
                                'on' => ['FINISH_C' => 'completed'],
                            ],
                            'completed' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'all_done' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    // Complete all three regions
    $state = $definition->transition(['type' => 'FINISH_A'], $state);
    $state = $definition->transition(['type' => 'FINISH_B'], $state);
    $state = $definition->transition(['type' => 'FINISH_C'], $state);

    // NOW @done should fire
    expect($state->matches('all_done'))->toBeTrue()
        ->and($state->currentStateDefinition->type)->toBe(StateDefinitionType::FINAL);
});

test('partial completion with mixed region depths does not trigger @done', function (): void {
    // Region C has a compound sub-state — even if its nested final is reached,
    // the region itself is not at its direct final state.
    $definition = MachineDefinition::define([
        'id'      => 'mixed_depth',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 'finished',
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => [
                                'on' => ['FINISH_A' => 'completed'],
                            ],
                            'completed' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => [
                                'on' => ['FINISH_B' => 'completed'],
                            ],
                            'completed' => ['type' => 'final'],
                        ],
                    ],
                    'region_c' => [
                        'initial' => 'sub_process',
                        'states'  => [
                            'sub_process' => [
                                'initial' => 'running',
                                'states'  => [
                                    'running' => [
                                        'on' => ['SUB_DONE' => 'sub_final'],
                                    ],
                                    'sub_final' => ['type' => 'final'],
                                ],
                            ],
                            'completed' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'finished' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    // Complete regions A and B
    $state = $definition->transition(['type' => 'FINISH_A'], $state);
    $state = $definition->transition(['type' => 'FINISH_B'], $state);

    // Trigger the nested final in region C's compound sub-state
    $state = $definition->transition(['type' => 'SUB_DONE'], $state);

    // Region C is at sub_process.sub_final (nested), NOT at region_c.completed (direct)
    // @done must NOT fire
    expect($state->matches('finished'))->toBeFalse(
        'Nested final in compound sub-state must not count as region completion'
    );
    expect($state->matches('processing.region_a.completed'))->toBeTrue()
        ->and($state->matches('processing.region_b.completed'))->toBeTrue()
        ->and($state->matches('processing.region_c.sub_process.sub_final'))->toBeTrue();
});
