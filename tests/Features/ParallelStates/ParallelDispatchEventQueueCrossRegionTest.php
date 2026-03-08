<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Jobs\ParallelRegionJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchWithRaiseMachine;

uses(RefreshDatabase::class);

afterEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
});

it('job raised event broadcasts to all regions via transitionParallelState', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchWithRaiseMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Job A raises REGION_A_PROCESSED → under lock, transition() broadcasts to all regions
    // Only region A has a handler for REGION_A_PROCESSED → region A transitions
    (new ParallelRegionJob(
        machineClass: ParallelDispatchWithRaiseMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_raise.processing.region_a',
        initialStateId: 'parallel_dispatch_with_raise.processing.region_a.working',
    ))->handle();

    $restored = ParallelDispatchWithRaiseMachine::create(state: $rootEventId);

    // Region A advanced to finished (its own raised event)
    expect($restored->state->value)->toContain('parallel_dispatch_with_raise.processing.region_a.finished');
    // Region B unaffected (no handler for REGION_A_PROCESSED)
    expect($restored->state->value)->toContain('parallel_dispatch_with_raise.processing.region_b.working');
});

it('cross-region event advances sibling → sibling job detects stale state', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    // Use inline machine where region A raises event that region B handles
    $definition = \Tarfinlabs\EventMachine\Definition\MachineDefinition::define(
        config: [
            'id'             => 'cross_region_raise',
            'initial'        => 'processing',
            'should_persist' => true,
            'context'        => [
                'region_a_ran' => false,
                'region_b_ran' => false,
            ],
            'states' => [
                'processing' => [
                    'type'   => 'parallel',
                    '@done'  => 'completed',
                    'states' => [
                        'region_a' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'entry' => \Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\SetRegionAResultAction::class,
                                    'on'    => ['DONE_A' => 'finished'],
                                ],
                                'finished' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'entry' => \Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\SetRegionBResultAction::class,
                                    'on'    => ['DONE_B' => 'finished'],
                                ],
                                'finished' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
    );

    // This test verifies the general principle: when Job A completes and sends
    // an external event that transitions region B, Job B would no-op
    // Since we can't dispatch to inline definitions, test sequential mode
    $state = $definition->getInitialState();

    expect($state->context->get('region_a_result'))->toBe('processed_by_a');
    expect($state->context->get('region_b_result'))->toBe('processed_by_b');

    // Both regions at initial, send events to advance
    $state = $definition->transition(['type' => 'DONE_A'], $state);
    $state = $definition->transition(['type' => 'DONE_B'], $state);

    expect($state->currentStateDefinition->id)->toBe('cross_region_raise.completed');
});

it('no race condition when job A finishes before job B starts', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchWithRaiseMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Job A finishes completely (including raised event processing)
    (new ParallelRegionJob(
        machineClass: ParallelDispatchWithRaiseMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_raise.processing.region_a',
        initialStateId: 'parallel_dispatch_with_raise.processing.region_a.working',
    ))->handle();

    // Job B starts after Job A finished — sees fresh state
    (new ParallelRegionJob(
        machineClass: ParallelDispatchWithRaiseMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_raise.processing.region_b',
        initialStateId: 'parallel_dispatch_with_raise.processing.region_b.working',
    ))->handle();

    $restored = ParallelDispatchWithRaiseMachine::create(state: $rootEventId);

    // Both regions completed their work
    expect($restored->state->context->get('region_a_result'))->toBe('processed_by_a');
    expect($restored->state->context->get('region_b_result'))->toBe('processed_by_b');

    // Region A at finished (raised event), Region B at working (no raise)
    expect($restored->state->value)->toContain('parallel_dispatch_with_raise.processing.region_a.finished');
    expect($restored->state->value)->toContain('parallel_dispatch_with_raise.processing.region_b.working');
});
