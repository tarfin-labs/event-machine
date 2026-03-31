<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Jobs\ParallelRegionJob;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\ProcessInternalGoAction;

uses(RefreshDatabase::class);

afterEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
});

test('internal raised events take priority over external events (SCXML test421)', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'internal_priority',
            'initial' => 'parallel_parent',
            'context' => [
                'raiseActionRan'     => false,
                'internalTransition' => false,
            ],
            'states' => [
                'parallel_parent' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'step_1_a',
                            'states'  => [
                                'step_1_a' => [
                                    'entry' => ProcessInternalGoAction::class,
                                    'on'    => [
                                        'INTERNAL_GO' => [
                                            'target'  => 'step_2_a',
                                            'actions' => 'logInternalAction',
                                        ],
                                    ],
                                ],
                                'step_2_a' => [],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'idle_b',
                            'states'  => [
                                'idle_b' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'logInternalAction' => function (ContextManager $context): void {
                    $context->set('internalTransition', true);
                },
            ],
        ]
    );

    $state = $definition->getInitialState();

    // Internal event should have been processed during initialization
    expect($state->context->get('raiseActionRan'))->toBeTrue();
    expect($state->context->get('internalTransition'))->toBeTrue();

    // Region A should be in step_2_a (transitioned by raised internal event)
    expect($state->value)->toContain('internal_priority.parallel_parent.region_a.step_2_a');
});

test('each dispatched job processes its raised events before releasing lock', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Run Job A — it may raise events, they are processed within the same lock scope
    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working',
    ))->handle();

    // Verify context was updated (entry action ran successfully)
    $restored = ParallelDispatchMachine::create(state: $rootEventId);
    expect($restored->state->context->get('regionAData'))->toBe('processed_by_a');
    expect($restored->state->isInParallelState())->toBeTrue();
});

test('areAllRegionsFinal does not count non-final states as final', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'final_check',
            'initial' => 'parallel_parent',
            'states'  => [
                'parallel_parent' => [
                    'type'   => 'parallel',
                    '@done'  => 'completed',
                    'states' => [
                        'region_a' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'on' => ['DONE_A' => 'final_a'],
                                ],
                                'final_a' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'on' => ['DONE_B' => 'final_b'],
                                ],
                                'final_b' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ]
    );

    $state = $definition->getInitialState();

    // Neither region is final
    $parallelParent = $definition->idMap['final_check.parallel_parent'];
    expect($definition->areAllRegionsFinal($parallelParent, $state))->toBeFalse();

    // Only region A final
    $state = $definition->transition(['type' => 'DONE_A'], $state);
    expect($state->isInParallelState())->toBeTrue();

    // Both regions final
    $state = $definition->transition(['type' => 'DONE_B'], $state);
    expect($state->currentStateDefinition->id)->toBe('final_check.completed');
});
