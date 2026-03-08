<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Jobs\ParallelRegionJob;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchChainedMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchWithFailMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchMultiRaiseMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchDeepContextMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchFailToParallelMachine;

uses(RefreshDatabase::class);

afterEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
});

// ============================================================
// Plan Test #18: @always transitions fire after parallel region completion
// Phase C — Core Dispatch Tests
// ============================================================

test('@always transitions fire after parallel region completion (plan #18)', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'always_parallel',
            'initial' => 'parallel_parent',
            'states'  => [
                'parallel_parent' => [
                    'type'   => 'parallel',
                    'onDone' => 'completed',
                    'states' => [
                        'region_a' => [
                            'initial' => 'waiting_a',
                            'states'  => [
                                'waiting_a' => [
                                    'on' => [
                                        '@always' => [
                                            ['target' => 'done_a', 'guards' => 'isSiblingApprovedGuard'],
                                        ],
                                        'ADVANCE_A' => 'done_a',
                                    ],
                                ],
                                'done_a' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'pending_b',
                            'states'  => [
                                'pending_b' => [
                                    'on' => ['APPROVE' => 'approved_b'],
                                ],
                                'approved_b' => [
                                    'on' => ['DONE_B' => 'done_b'],
                                ],
                                'done_b' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'guards' => [
                'isSiblingApprovedGuard' => fn (ContextManager $ctx, EventBehavior $event, State $state) => $state->matches('parallel_parent.region_b.approved_b')
                    || $state->matches('parallel_parent.region_b.done_b'),
            ],
        ]
    );

    $state = $definition->getInitialState();
    expect($state->isInParallelState())->toBeTrue();

    // Region A is waiting (guard not passed — region B not yet approved)
    expect($state->value)->toContain('always_parallel.parallel_parent.region_a.waiting_a');

    // Approve in region B → @always guard in region A re-evaluates and passes
    $state = $definition->transition(['type' => 'APPROVE'], $state);

    // @always guard passed → region A auto-transitions to done_a
    expect($state->value)->toContain('always_parallel.parallel_parent.region_a.done_a');
    expect($state->value)->toContain('always_parallel.parallel_parent.region_b.approved_b');

    // Complete region B
    $state = $definition->transition(['type' => 'DONE_B'], $state);

    // Both regions final → onDone fires
    expect($state->currentStateDefinition->id)->toBe('always_parallel.completed');
});

// ============================================================
// Plan Test #36: Deeply nested context keys merge correctly
// Phase D — Ecosystem Edge Cases (Context Merge)
// ============================================================

test('deeply nested context keys merge correctly (plan #36)', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchDeepContextMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Job A writes: report.findeks = {score: 750, provider: kkb}
    (new ParallelRegionJob(
        machineClass: ParallelDispatchDeepContextMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_deep.processing.region_a',
        initialStateId: 'parallel_deep.processing.region_a.working_a',
    ))->handle();

    // Job B writes: report.turmob = {status: clean, checked_at: ...}
    (new ParallelRegionJob(
        machineClass: ParallelDispatchDeepContextMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_deep.processing.region_b',
        initialStateId: 'parallel_deep.processing.region_b.working_b',
    ))->handle();

    $restored = ParallelDispatchDeepContextMachine::create(state: $rootEventId);

    // Deep merge: both sub-keys under 'report' should be present
    $report = $restored->state->context->get('report');
    expect($report)->toBeArray();
    expect($report)->toHaveKey('findeks');
    expect($report)->toHaveKey('turmob');
    expect($report['findeks']['score'])->toBe(750);
    expect($report['findeks']['provider'])->toBe('kkb');
    expect($report['turmob']['status'])->toBe('clean');
    expect($report['turmob']['checked_at'])->toBe('2026-03-08');

    // Top-level keys also preserved
    expect($restored->state->context->get('region_a_result'))->toBe('processed_by_a');
    expect($restored->state->context->get('region_b_result'))->toBe('processed_by_b');
});

// ============================================================
// Plan Test #37: Same top-level key → last writer wins (scalars)
// Phase D — Ecosystem Edge Cases (Context Merge)
// ============================================================

test('same scalar context key → last writer wins (plan #37)', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    // Use DeepContextMachine — both regions write to 'report' but since both are
    // arrays they deep-merge. Test scalar conflict with region_a_result/region_b_result
    // which are separate keys (no conflict). For a true scalar conflict, we test
    // the computeContextDiff + merge logic directly.
    $machine = ParallelDispatchDeepContextMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Both jobs run — they write different keys, so no scalar conflict
    (new ParallelRegionJob(
        machineClass: ParallelDispatchDeepContextMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_deep.processing.region_a',
        initialStateId: 'parallel_deep.processing.region_a.working_a',
    ))->handle();

    (new ParallelRegionJob(
        machineClass: ParallelDispatchDeepContextMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_deep.processing.region_b',
        initialStateId: 'parallel_deep.processing.region_b.working_b',
    ))->handle();

    // For arrays written to same key: deep merge combines them
    $restored = ParallelDispatchDeepContextMachine::create(state: $rootEventId);
    $report   = $restored->state->context->get('report');
    expect($report)->toHaveKey('findeks');
    expect($report)->toHaveKey('turmob');

    // Verify the arrayRecursiveMerge behavior: scalar values under same key = last wins
    $job      = new ParallelRegionJob('', '', '', '');
    $mergeRef = new ReflectionMethod($job, 'arrayRecursiveMerge');
    $result   = $mergeRef->invoke($job, ['status' => 'a_done'], ['status' => 'b_done']);
    expect($result['status'])->toBe('b_done');

    // Nested: deep merge preserves both sub-keys
    $result = $mergeRef->invoke(
        $job,
        ['report' => ['findeks' => ['score' => 750]]],
        ['report' => ['turmob' => ['status' => 'clean']]],
    );
    expect($result['report']['findeks']['score'])->toBe(750);
    expect($result['report']['turmob']['status'])->toBe('clean');
});

// ============================================================
// Plan Test #44: Transition INTO nested parallel state dispatches
// Phase D — Ecosystem Edge Cases (Nested Parallel)
// ============================================================

test('nested parallel state within region enters correctly (plan #44)', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'nested_parallel',
            'initial' => 'outer_parallel',
            'states'  => [
                'outer_parallel' => [
                    'type'   => 'parallel',
                    'onDone' => 'completed',
                    'states' => [
                        'region_a' => [
                            'initial' => 'step_a',
                            'states'  => [
                                'step_a' => [
                                    'on' => ['ENTER_INNER' => 'inner_parallel'],
                                ],
                                'inner_parallel' => [
                                    'type'   => 'parallel',
                                    'onDone' => 'done_a',
                                    'states' => [
                                        'sub_region_1' => [
                                            'initial' => 'sub_working_1',
                                            'states'  => [
                                                'sub_working_1' => [
                                                    'on' => ['SUB_1_DONE' => 'sub_final_1'],
                                                ],
                                                'sub_final_1' => ['type' => 'final'],
                                            ],
                                        ],
                                        'sub_region_2' => [
                                            'initial' => 'sub_working_2',
                                            'states'  => [
                                                'sub_working_2' => [
                                                    'on' => ['SUB_2_DONE' => 'sub_final_2'],
                                                ],
                                                'sub_final_2' => ['type' => 'final'],
                                            ],
                                        ],
                                    ],
                                ],
                                'done_a' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'waiting_b',
                            'states'  => [
                                'waiting_b' => [
                                    'on' => ['DONE_B' => 'done_b'],
                                ],
                                'done_b' => ['type' => 'final'],
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

    // Transition into nested parallel
    $state = $definition->transition(['type' => 'ENTER_INNER'], $state);

    // Inner parallel regions should be active
    expect($state->value)->toContain('nested_parallel.outer_parallel.region_a.inner_parallel.sub_region_1.sub_working_1');
    expect($state->value)->toContain('nested_parallel.outer_parallel.region_a.inner_parallel.sub_region_2.sub_working_2');

    // Complete inner sub-regions
    $state = $definition->transition(['type' => 'SUB_1_DONE'], $state);
    $state = $definition->transition(['type' => 'SUB_2_DONE'], $state);

    // Inner onDone → done_a (region A reaches final)
    expect($state->value)->toContain('nested_parallel.outer_parallel.region_a.done_a');

    // Complete region B
    $state = $definition->transition(['type' => 'DONE_B'], $state);

    // Outer onDone fires
    expect($state->currentStateDefinition->id)->toBe('nested_parallel.completed');
});

// ============================================================
// Plan Test #45: Three-level nesting (parallel → region → parallel → region)
// Phase D — Ecosystem Edge Cases (Nested Parallel)
// ============================================================

test('three-level nesting: outer parallel → inner parallel → leaf states (plan #45)', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'three_level',
            'initial' => 'level_1',
            'states'  => [
                'level_1' => [
                    'type'   => 'parallel',
                    'onDone' => 'completed',
                    'states' => [
                        'branch_a' => [
                            'initial' => 'level_2',
                            'states'  => [
                                'level_2' => [
                                    'type'   => 'parallel',
                                    'onDone' => 'branch_a_done',
                                    'states' => [
                                        'leaf_1' => [
                                            'initial' => 'active_1',
                                            'states'  => [
                                                'active_1' => [
                                                    'on' => ['FINISH_1' => 'final_1'],
                                                ],
                                                'final_1' => ['type' => 'final'],
                                            ],
                                        ],
                                        'leaf_2' => [
                                            'initial' => 'active_2',
                                            'states'  => [
                                                'active_2' => [
                                                    'on' => ['FINISH_2' => 'final_2'],
                                                ],
                                                'final_2' => ['type' => 'final'],
                                            ],
                                        ],
                                    ],
                                ],
                                'branch_a_done' => ['type' => 'final'],
                            ],
                        ],
                        'branch_b' => [
                            'initial' => 'simple_b',
                            'states'  => [
                                'simple_b' => [
                                    'on' => ['FINISH_B' => 'final_b'],
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

    // Inner parallel regions + outer region B should all be active
    expect($state->value)->toContain('three_level.level_1.branch_a.level_2.leaf_1.active_1');
    expect($state->value)->toContain('three_level.level_1.branch_a.level_2.leaf_2.active_2');
    expect($state->value)->toContain('three_level.level_1.branch_b.simple_b');

    // Complete inner parallel leaves
    $state = $definition->transition(['type' => 'FINISH_1'], $state);
    $state = $definition->transition(['type' => 'FINISH_2'], $state);

    // Inner onDone → branch_a_done (branch A reaches final)
    expect($state->value)->toContain('three_level.level_1.branch_a.branch_a_done');

    // Complete branch B
    $state = $definition->transition(['type' => 'FINISH_B'], $state);

    // Outer onDone fires → completed
    expect($state->currentStateDefinition->id)->toBe('three_level.completed');
});

// ============================================================
// Plan Test #58: Chained dispatch — onDone target is another parallel state
// Phase E — Review-Discovered Edge Cases
// ============================================================

test('chained dispatch: onDone target is another parallel state (plan #58)', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchChainedMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Phase 1: Run both region jobs
    (new ParallelRegionJob(
        machineClass: ParallelDispatchChainedMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_chained.phase_one.region_a',
        initialStateId: 'parallel_chained.phase_one.region_a.working_a',
    ))->handle();

    (new ParallelRegionJob(
        machineClass: ParallelDispatchChainedMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_chained.phase_one.region_b',
        initialStateId: 'parallel_chained.phase_one.region_b.working_b',
    ))->handle();

    // Transition phase 1 regions to final
    $machine = ParallelDispatchChainedMachine::create(state: $rootEventId);
    $machine->send('REGION_A_DONE');

    $machine = ParallelDispatchChainedMachine::create(state: $rootEventId);
    $machine->send('REGION_B_DONE');

    // Phase 1 onDone → phase_two (another parallel state)
    $machine = ParallelDispatchChainedMachine::create(state: $rootEventId);
    expect($machine->state->isInParallelState())->toBeTrue();

    // Phase 2 should have pending dispatches for new regions
    expect($machine->state->value)->toContain('parallel_chained.phase_two.region_c.working_c');
    expect($machine->state->value)->toContain('parallel_chained.phase_two.region_d.working_d');

    // Run phase 2 jobs
    (new ParallelRegionJob(
        machineClass: ParallelDispatchChainedMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_chained.phase_two.region_c',
        initialStateId: 'parallel_chained.phase_two.region_c.working_c',
    ))->handle();

    (new ParallelRegionJob(
        machineClass: ParallelDispatchChainedMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_chained.phase_two.region_d',
        initialStateId: 'parallel_chained.phase_two.region_d.working_d',
    ))->handle();

    // Transition phase 2 regions to final
    $machine = ParallelDispatchChainedMachine::create(state: $rootEventId);
    $machine->send('REGION_C_DONE');

    $machine = ParallelDispatchChainedMachine::create(state: $rootEventId);
    $machine->send('REGION_D_DONE');

    // Phase 2 onDone → completed
    $final = ParallelDispatchChainedMachine::create(state: $rootEventId);
    expect($final->state->currentStateDefinition->id)->toBe('parallel_chained.completed');
});

// ============================================================
// Plan Test #60: Entry action raises multiple events → all processed in order
// Phase F — Event Queue Isolation Tests
// ============================================================

test('entry action raises multiple events → all processed in order (plan #60)', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMultiRaiseMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Job A runs entry action which raises STEP_1_DONE then STEP_2_DONE
    (new ParallelRegionJob(
        machineClass: ParallelDispatchMultiRaiseMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_multi_raise.processing.region_a',
        initialStateId: 'parallel_multi_raise.processing.region_a.step_initial',
    ))->handle();

    $restored = ParallelDispatchMultiRaiseMachine::create(state: $rootEventId);

    // Both raised events should have been processed in order:
    // step_initial → (STEP_1_DONE) → step_1 → (STEP_2_DONE) → finished_a
    expect($restored->state->value)->toContain('parallel_multi_raise.processing.region_a.finished_a');
    expect($restored->state->context->get('region_a_result'))->toBe('processed_by_a');
});

// ============================================================
// Plan Test #78: One region completes with compound onDone, then sibling fails
// Phase G — @fail Event Tests
// ============================================================

test('compound onDone completes in one region, then sibling fails → context preserved (plan #78)', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    // Use ParallelDispatchWithFailMachine — region B completes, region A fails
    $machine = ParallelDispatchWithFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Region B completes its entry action successfully
    (new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_fail.processing.region_b',
        initialStateId: 'parallel_fail.processing.region_b.working_b',
    ))->handle();

    // Verify B's context is persisted
    $afterB = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    expect($afterB->state->context->get('region_b_result'))->toBe('processed_by_b');

    // Transition B to final
    $afterB->send('REGION_B_DONE');

    // Now Region A fails
    $jobA = new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_fail.processing.region_a',
        initialStateId: 'parallel_fail.processing.region_a.working_a',
    );
    $jobA->failed(new \RuntimeException('API timeout'));

    // Machine should be in error state
    $restored = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('parallel_fail.error');

    // Region B's completed context should be preserved
    expect($restored->state->context->get('region_b_result'))->toBe('processed_by_b');
});

// ============================================================
// Plan Test #80: onFail target is another parallel state → new dispatch cycle
// Phase G — @fail Event Tests
// ============================================================

test('onFail target is parallel state → new dispatch cycle (plan #80)', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchFailToParallelMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Primary processing — region A fails
    $jobA = new ParallelRegionJob(
        machineClass: ParallelDispatchFailToParallelMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_fail_to_parallel.primary_processing.region_a',
        initialStateId: 'parallel_fail_to_parallel.primary_processing.region_a.working_a',
    );
    $jobA->failed(new \RuntimeException('Primary API failed'));

    // Machine should transition to fallback_processing (another parallel state)
    $restored = ParallelDispatchFailToParallelMachine::create(state: $rootEventId);
    expect($restored->state->isInParallelState())->toBeTrue();

    // Fallback parallel state regions should be active
    expect($restored->state->value)->toContain('parallel_fail_to_parallel.fallback_processing.fallback_a.retrying_a');
    expect($restored->state->value)->toContain('parallel_fail_to_parallel.fallback_processing.fallback_b.retrying_b');

    // Run fallback jobs
    (new ParallelRegionJob(
        machineClass: ParallelDispatchFailToParallelMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_fail_to_parallel.fallback_processing.fallback_a',
        initialStateId: 'parallel_fail_to_parallel.fallback_processing.fallback_a.retrying_a',
    ))->handle();

    (new ParallelRegionJob(
        machineClass: ParallelDispatchFailToParallelMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_fail_to_parallel.fallback_processing.fallback_b',
        initialStateId: 'parallel_fail_to_parallel.fallback_processing.fallback_b.retrying_b',
    ))->handle();

    // Complete fallback regions
    $machine = ParallelDispatchFailToParallelMachine::create(state: $rootEventId);
    $machine->send('FALLBACK_A_DONE');

    $machine = ParallelDispatchFailToParallelMachine::create(state: $rootEventId);
    $machine->send('FALLBACK_B_DONE');

    // Fallback onDone → completed
    $final = ParallelDispatchFailToParallelMachine::create(state: $rootEventId);
    expect($final->state->currentStateDefinition->id)->toBe('parallel_fail_to_parallel.completed');
});
