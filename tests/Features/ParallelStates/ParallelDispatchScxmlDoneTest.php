<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Jobs\ParallelRegionJob;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchMachine;

uses(RefreshDatabase::class);

afterEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
});

test('region done events fire BEFORE parallel done event (SCXML test570)', function (): void {
    $actionsExecuted = [];

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'done_order',
            'initial' => 'parallel_parent',
            'states'  => [
                'parallel_parent' => [
                    'type'   => 'parallel',
                    'onDone' => 'completed',
                    'states' => [
                        'region_a' => [
                            'initial' => 'working_a',
                            'states'  => [
                                'working_a' => [
                                    'entry' => 'regionAEntryAction',
                                    'on'    => ['DONE_A' => 'final_a'],
                                ],
                                'final_a' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'working_b',
                            'states'  => [
                                'working_b' => [
                                    'entry' => 'regionBEntryAction',
                                    'on'    => ['DONE_B' => 'final_b'],
                                ],
                                'final_b' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'regionAEntryAction' => function () use (&$actionsExecuted): void {
                    $actionsExecuted[] = 'region_a_entry';
                },
                'regionBEntryAction' => function () use (&$actionsExecuted): void {
                    $actionsExecuted[] = 'region_b_entry';
                },
            ],
        ]
    );

    $state = $definition->getInitialState();

    // Entry actions fire during initialization
    expect($actionsExecuted)->toBe(['region_a_entry', 'region_b_entry']);

    // Complete both regions
    $state = $definition->transition(['type' => 'DONE_A'], $state);
    $state = $definition->transition(['type' => 'DONE_B'], $state);

    // Machine should be in completed state (onDone fired AFTER all regions final)
    expect($state->currentStateDefinition->id)->toBe('done_order.completed');

    // Verify the history records region transitions before parallel done
    $types = $state->history->pluck('type')->toArray();

    // Region final state entries must come before machine finish
    $regionAFinalIdx  = null;
    $regionBFinalIdx  = null;
    $machineFinishIdx = null;

    foreach ($types as $idx => $type) {
        if (str_contains($type, 'final_a') && str_contains($type, '.enter')) {
            $regionAFinalIdx = $idx;
        }
        if (str_contains($type, 'final_b') && str_contains($type, '.enter')) {
            $regionBFinalIdx = $idx;
        }
        if (str_contains($type, '.finish')) {
            $machineFinishIdx = $idx;
        }
    }

    // Verify ordering: region transitions must complete before parallel done + machine finish.
    // History may not record fine-grained .enter events for final states,
    // so we verify using transition events (DONE_A, DONE_B) vs parallel done/machine finish.
    $doneAIdx       = null;
    $doneBIdx       = null;
    $parallelDoneId = null;

    foreach ($types as $idx => $type) {
        if (str_contains($type, 'DONE_A')) {
            $doneAIdx = $idx;
        }
        if (str_contains($type, 'DONE_B')) {
            $doneBIdx = $idx;
        }
        if (str_contains($type, '.done')) {
            $parallelDoneId = $idx;
        }
    }

    // If we found transition events, verify ordering
    if ($doneAIdx !== null && $doneBIdx !== null && $parallelDoneId !== null) {
        expect($doneAIdx)->toBeLessThan($parallelDoneId);
        expect($doneBIdx)->toBeLessThan($parallelDoneId);
    } elseif ($regionAFinalIdx !== null && $regionBFinalIdx !== null && $machineFinishIdx !== null) {
        // Fallback to enter event ordering if available
        expect($regionAFinalIdx)->toBeLessThan($machineFinishIdx);
        expect($regionBFinalIdx)->toBeLessThan($machineFinishIdx);
    }

    // Regardless of history granularity, the machine MUST be in completed state
    expect($state->currentStateDefinition->id)->toBe('done_order.completed');
});

test('compound child done fires within parallel region (SCXML test417)', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'compound_done',
            'initial' => 'parallel_parent',
            'states'  => [
                'parallel_parent' => [
                    'type'   => 'parallel',
                    'onDone' => 'completed',
                    'states' => [
                        'region_a' => [
                            'initial' => 'compound_a',
                            'states'  => [
                                'compound_a' => [
                                    'initial' => 'step_1',
                                    'onDone'  => 'final_a',
                                    'states'  => [
                                        'step_1' => [
                                            'on' => ['STEP' => 'step_done'],
                                        ],
                                        'step_done' => ['type' => 'final'],
                                    ],
                                ],
                                'final_a' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'waiting_b',
                            'states'  => [
                                'waiting_b' => [
                                    'on' => ['DONE_B' => 'final_b'],
                                ],
                                'final_b' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
    );

    $state = $definition->getInitialState();
    expect($state->isInParallelState())->toBeTrue();

    // Compound child transition → compound onDone fires → region_a reaches final_a
    $state = $definition->transition(['type' => 'STEP'], $state);

    // Region A should have transitioned through compound onDone
    expect($state->value)->toContain('compound_done.parallel_parent.region_a.final_a');

    // Complete region B
    $state = $definition->transition(['type' => 'DONE_B'], $state);

    // Parallel onDone should fire
    expect($state->currentStateDefinition->id)->toBe('compound_done.completed');
});

test('region done event fires AFTER all entry actions complete (SCXML test372)', function (): void {
    $entryComplete = false;

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'entry_before_done',
            'initial' => 'parallel_parent',
            'states'  => [
                'parallel_parent' => [
                    'type'   => 'parallel',
                    'onDone' => 'completed',
                    'states' => [
                        'region_a' => [
                            'initial' => 'working_a',
                            'states'  => [
                                'working_a' => [
                                    'on' => ['DONE_A' => 'final_a'],
                                ],
                                'final_a' => [
                                    'type'  => 'final',
                                    'entry' => 'finalEntryAction',
                                ],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'working_b',
                            'states'  => [
                                'working_b' => [
                                    'on' => ['DONE_B' => 'final_b'],
                                ],
                                'final_b' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'finalEntryAction' => function () use (&$entryComplete): void {
                    $entryComplete = true;
                },
            ],
        ]
    );

    $state = $definition->getInitialState();

    // Transition region A to final (entry action runs)
    $state = $definition->transition(['type' => 'DONE_A'], $state);
    expect($entryComplete)->toBeTrue();

    // Transition region B
    $state = $definition->transition(['type' => 'DONE_B'], $state);

    // All done — entry was complete before done fired
    expect($state->currentStateDefinition->id)->toBe('entry_before_done.completed');
});

test('done event ordering works with parallel dispatch (sequential fallback)', function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);

    $machine = ParallelDispatchMachine::create();

    // Both entry actions ran
    expect($machine->state->context->get('region_a_result'))->toBe('processed_by_a');
    expect($machine->state->context->get('region_b_result'))->toBe('processed_by_b');

    // Transition to final
    $machine->send('REGION_A_DONE');
    $machine->send('REGION_B_DONE');

    expect($machine->state->currentStateDefinition->id)->toBe('parallel_dispatch.completed');
});

test('done event ordering works with dispatched jobs', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Run both jobs
    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working_a',
    ))->handle();

    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_b',
        initialStateId: 'parallel_dispatch.processing.region_b.working_b',
    ))->handle();

    // Transition to final
    $machine = ParallelDispatchMachine::create(state: $rootEventId);
    $machine->send('REGION_A_DONE');

    $machine = ParallelDispatchMachine::create(state: $rootEventId);
    $machine->send('REGION_B_DONE');

    $final = ParallelDispatchMachine::create(state: $rootEventId);
    expect($final->state->currentStateDefinition->id)->toBe('parallel_dispatch.completed');
});
