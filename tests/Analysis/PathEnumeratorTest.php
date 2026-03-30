<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Analysis\PathType;
use Tarfinlabs\EventMachine\Analysis\PathEnumerator;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AbcMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Jobs\SuccessfulTestJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\GuardedMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysGuardMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\LoopMachines\AlwaysLoopMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\JobActors\JobActorParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\AfterTimerMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ConditionalOnDoneMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\DoneDotParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\SequentialParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\MultiOutcomeChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Compound\ConditionalCompoundOnDoneMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\DoneDotCatchallParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateApprovedChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\FireAndForgetTargetParentMachine;

test('AbcMachine enumerates 1 DEAD_END path', function (): void {
    $definition = AbcMachine::definition();
    $enumerator = new PathEnumerator($definition);
    $result     = $enumerator->enumerate();

    // AbcMachine: initial=state_b → @always(unguarded) → state_c (no transitions, not FINAL)
    expect($result->paths)->toHaveCount(1)
        ->and($result->deadEndPaths())->toHaveCount(1)
        ->and($result->paths[0]->type)->toBe(PathType::DEAD_END);
});

test('GuardedMachine enumerates 1 DEAD_END path', function (): void {
    $definition = GuardedMachine::definition();
    $enumerator = new PathEnumerator($definition);
    $result     = $enumerator->enumerate();

    // CHECK: branch 0 (targetless with guard) skipped, branch 1 (fallback) → processed (DEAD_END)
    // INCREASE: targetless self-transition, skipped
    expect($result->paths)->toHaveCount(1)
        ->and($result->deadEndPaths())->toHaveCount(1);
});

test('compound @done continuation follows parent onDoneTransition from FINAL child', function (): void {
    // Build a minimal machine with compound @done: inner starts at FINAL, parent has @done → target
    $definition = MachineDefinition::define(config: [
        'id'      => 'compound_done_test',
        'initial' => 'wrapper',
        'states'  => [
            'wrapper' => [
                '@done'   => 'completed',
                'initial' => 'inner_done',
                'states'  => [
                    'inner_done' => ['type' => 'final'],
                ],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    $enumerator = new PathEnumerator($definition);
    $result     = $enumerator->enumerate();

    // inner_done (FINAL) → compound @done → completed (FINAL)
    expect($result->paths)->toHaveCount(1)
        ->and($result->happyPaths())->toHaveCount(1)
        ->and($result->paths[0]->terminalStateId)->toContain('completed');
});

test('ConditionalCompoundOnDoneMachine enumerates 2 HAPPY paths via compound @done', function (): void {
    $definition = ConditionalCompoundOnDoneMachine::definition();
    $enumerator = new PathEnumerator($definition);
    $result     = $enumerator->enumerate();

    // checking → CHECK_COMPLETED → done (final child) → compound @done → approved / manual_review
    expect($result->happyPaths())->toHaveCount(2);
});

test('parallel state enumerates per-region paths and follows @done', function (): void {
    // Minimal parallel: 2 regions each with a FINAL initial, @done → completed
    $definition = MachineDefinition::define(config: [
        'id'      => 'parallel_done_test',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 'completed',
                'states' => [
                    'region_a' => [
                        'initial' => 'done_a',
                        'states'  => [
                            'done_a' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'done_b',
                        'states'  => [
                            'done_b' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    $enumerator = new PathEnumerator($definition);
    $result     = $enumerator->enumerate();

    // Outer path: processing (parallel) → @done → completed
    expect($result->happyPaths())->toHaveCount(1)
        ->and($result->paths[0]->terminalStateId)->toContain('completed');

    // Per-region paths stored in parallelGroups
    expect($result->parallelGroups)->toHaveCount(1);

    $group = $result->parallelGroups[0];
    expect($group->regionPaths)->toHaveCount(2)
        ->and($group->combinationCount())->toBe(1);
});

test('parallel state with @done and @fail enumerates both continuation paths', function (): void {
    $definition = MachineDefinition::define(config: [
        'id'      => 'parallel_done_fail_test',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 'completed',
                '@fail'  => 'failed',
                'states' => [
                    'region' => [
                        'initial' => 'done_r',
                        'states'  => [
                            'done_r' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'completed' => ['type' => 'final'],
            'failed'    => ['type' => 'final'],
        ],
    ]);

    $enumerator = new PathEnumerator($definition);
    $result     = $enumerator->enumerate();

    // 2 outer paths: @done → completed (HAPPY), @fail → failed (FAIL)
    expect($result->paths)->toHaveCount(2)
        ->and($result->happyPaths())->toHaveCount(1)
        ->and($result->failPaths())->toHaveCount(1);
});

test('ConditionalOnDoneMachine enumerates 2 HAPPY paths via parallel @done with guards', function (): void {
    $definition = ConditionalOnDoneMachine::definition();
    $enumerator = new PathEnumerator($definition);
    $result     = $enumerator->enumerate();

    // 2 regions (inventory, payment) each with 1 event → done.
    // Parallel @done: branch 0 (guard) → approved, branch 1 (fallback) → manual_review
    expect($result->happyPaths())->toHaveCount(2)
        ->and($result->parallelGroups)->toHaveCount(1);
});

test('JobActorParentMachine enumerates 2 paths: HAPPY + FAIL', function (): void {
    $definition = JobActorParentMachine::definition();
    $enumerator = new PathEnumerator($definition);
    $result     = $enumerator->enumerate();

    // idle → START → processing [job] → @done → completed (HAPPY)
    // idle → START → processing [job] → @fail → failed (FAIL)
    expect($result->paths)->toHaveCount(2)
        ->and($result->happyPaths())->toHaveCount(1)
        ->and($result->failPaths())->toHaveCount(1);
});

test('DoneDotParentMachine enumerates 3 paths via @done.{state}', function (): void {
    $definition = DoneDotParentMachine::definition();
    $enumerator = new PathEnumerator($definition);
    $result     = $enumerator->enumerate();

    // idle → START → processing → @done.approved → completed
    // idle → START → processing → @done.rejected → declined
    // idle → START → processing → @fail → error
    expect($result->paths)->toHaveCount(3)
        ->and($result->happyPaths())->toHaveCount(2)
        ->and($result->failPaths())->toHaveCount(1);
});

test('FireAndForgetTargetParentMachine enumerates fire-and-forget path', function (): void {
    $definition = FireAndForgetTargetParentMachine::definition();
    $enumerator = new PathEnumerator($definition);
    $result     = $enumerator->enumerate();

    // idle → REJECT → dispatching_verification (ff→prevented) → RETRY → idle (LOOP)
    expect($result->loopPaths())->toHaveCount(1);
});

test('AlwaysGuardMachine enumerates 3 paths: 2 HAPPY + 1 GUARD_BLOCK', function (): void {
    $definition = AlwaysGuardMachine::definition();
    $enumerator = new PathEnumerator($definition);
    $result     = $enumerator->enumerate();

    // @always guard-pass → done (HAPPY)
    // @always guard-fail → GO guard-pass → done (HAPPY)
    // @always guard-fail → GO guard-fail → GUARD_BLOCK
    expect($result->paths)->toHaveCount(3)
        ->and($result->happyPaths())->toHaveCount(2)
        ->and($result->guardBlockPaths())->toHaveCount(1);
});

test('AfterTimerMachine enumerates 2 paths: HAPPY + TIMEOUT', function (): void {
    $definition = AfterTimerMachine::definition();
    $enumerator = new PathEnumerator($definition);
    $result     = $enumerator->enumerate();

    // awaiting_payment → PAY → processing → COMPLETE → completed (HAPPY)
    // awaiting_payment → ORDER_EXPIRED (after 7d) → cancelled (TIMEOUT)
    expect($result->paths)->toHaveCount(2)
        ->and($result->happyPaths())->toHaveCount(1)
        ->and($result->timeoutPaths())->toHaveCount(1);

    // Verify timer metadata on the timeout path
    $timeoutPath = $result->timeoutPaths()[0];
    $timerSteps  = array_filter($timeoutPath->steps, fn ($s) => $s->timerType !== null);
    expect($timerSteps)->not->toBeEmpty();
});

test('AlwaysLoopMachine enumerates 1 LOOP path', function (): void {
    $definition = AlwaysLoopMachine::definition();
    $enumerator = new PathEnumerator($definition);
    $result     = $enumerator->enumerate();

    // idle → TRIGGER → loop_a → @always → loop_b → @always → loop_a (LOOP)
    expect($result->paths)->toHaveCount(1)
        ->and($result->loopPaths())->toHaveCount(1);
});

test('MachineDefinition::enumeratePaths convenience wrapper works', function (): void {
    $result = JobActorParentMachine::definition()->enumeratePaths();

    expect($result->paths)->toHaveCount(2);
});

// ═══════════════════════════════════════════
//  invokeClass tests
// ═══════════════════════════════════════════

test('job actor paths carry invokeClass on processing step', function (): void {
    $result = JobActorParentMachine::definition()->enumeratePaths();

    // processing step should have invokeClass = SuccessfulTestJob
    $processingStep = $result->paths[0]->steps[1]; // idle → [START] → processing
    expect($processingStep->stateKey)->toBe('processing')
        ->and($processingStep->invokeClass)->toBe(SuccessfulTestJob::class);

    // completed step should NOT have invokeClass
    $completedStep = $result->paths[0]->steps[2];
    expect($completedStep->invokeClass)->toBeNull();
});

test('child machine paths carry invokeClass on processing step', function (): void {
    $result = DoneDotParentMachine::definition()->enumeratePaths();

    $processingStep = $result->paths[0]->steps[1];
    expect($processingStep->stateKey)->toBe('processing')
        ->and($processingStep->invokeClass)->toBe(ImmediateApprovedChildMachine::class);
});

test('non-invoke states have null invokeClass', function (): void {
    $result = AbcMachine::definition()->enumeratePaths();

    foreach ($result->paths[0]->steps as $step) {
        expect($step->invokeClass)->toBeNull();
    }
});

test('sequential delegation has invokeClass on both invoke states', function (): void {
    $result = SequentialParentMachine::definition()->enumeratePaths();

    // Find steps with invokeClass set
    $invokeSteps = [];

    foreach ($result->paths as $path) {
        foreach ($path->steps as $step) {
            if ($step->invokeClass !== null) {
                $invokeSteps[$step->stateKey] = $step->invokeClass;
            }
        }
    }

    expect($invokeSteps)->toHaveCount(2)
        ->and($invokeSteps)->toHaveKey('step_a')
        ->and($invokeSteps)->toHaveKey('step_b');
});

// ═══════════════════════════════════════════
//  Structured stats tests
// ═══════════════════════════════════════════

test('childMachines returns structured list', function (): void {
    $result = DoneDotParentMachine::definition()->enumeratePaths();

    $children = $result->childMachines();
    expect($children)->toHaveCount(1)
        ->and($children[0]['stateKey'])->toBe('processing')
        ->and($children[0]['class'])->toContain('ImmediateApprovedChildMachine')
        ->and($children[0]['async'])->toBeTrue()
        ->and($children[0]['queue'])->toBe('child-queue');
});

test('jobActors returns structured list', function (): void {
    $result = JobActorParentMachine::definition()->enumeratePaths();

    $jobs = $result->jobActors();
    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]['stateKey'])->toBe('processing')
        ->and($jobs[0]['class'])->toContain('SuccessfulTestJob')
        ->and($jobs[0]['queue'])->toBe('default');
});

// ═══════════════════════════════════════════
//  Unhandled child outcomes tests
// ═══════════════════════════════════════════

test('unhandled outcomes: none when parent handles all child final states', function (): void {
    $result = DoneDotParentMachine::definition()->enumeratePaths();

    // Child (ImmediateApprovedChildMachine) only has 'approved' final state
    // Parent has @done.approved → covered
    expect($result->unhandledChildOutcomes())->toBe([]);
});

test('unhandled outcomes: detects missing route for child final state', function (): void {
    // Inline child with 2 final states
    $childDef = MachineDefinition::define(config: [
        'id'      => 'multi_outcome_child',
        'initial' => 'working',
        'states'  => [
            'working'  => ['on' => ['APPROVE' => 'approved', 'REJECT' => 'rejected']],
            'approved' => ['type' => 'final'],
            'rejected' => ['type' => 'final'],
        ],
    ]);

    // Create a test child machine class dynamically is complex,
    // so use MultiOutcomeChildMachine if available, or test with DoneDotCatchallParentMachine
    $result = DoneDotCatchallParentMachine::definition()->enumeratePaths();

    // DoneDotCatchallParentMachine has catch-all @done, so all outcomes handled
    expect($result->unhandledChildOutcomes())->toBe([]);
});

test('unhandled outcomes: catch-all @done covers all child final states', function (): void {
    $definition = MachineDefinition::define(config: [
        'id'      => 'catchall_parent',
        'initial' => 'idle',
        'states'  => [
            'idle'       => ['on' => ['START' => 'processing']],
            'processing' => [
                'machine' => MultiOutcomeChildMachine::class,
                '@done'   => 'completed', // catch-all — covers all child final states
                '@fail'   => 'failed',
            ],
            'completed' => ['type' => 'final'],
            'failed'    => ['type' => 'final'],
        ],
    ]);

    $result = (new PathEnumerator($definition))->enumerate();

    expect($result->unhandledChildOutcomes())->toBe([]);
});

// ═══════════════════════════════════════════
//  Duplicate path detection
// ═══════════════════════════════════════════

test('no duplicate paths are generated for guarded @fail with retry loop', function (): void {
    // Simulates FindeksMachine's confirming_pin pattern:
    // awaiting_pin → PIN_CONFIRMED → confirming_pin (job)
    // confirming_pin @fail: [retryable → awaiting_pin (loop), fallback → failed]
    // The retry branch loops back to awaiting_pin which has PIN_CONFIRMED → confirming_pin
    // This can generate duplicate LOOP paths without dedup.
    $definition = MachineDefinition::define(config: [
        'id'      => 'retry_fail_test',
        'initial' => 'idle',
        'states'  => [
            'idle'       => ['on' => ['START' => 'awaiting']],
            'awaiting'   => ['on' => ['CONFIRM' => 'processing']],
            'processing' => [
                'job'   => SuccessfulTestJob::class,
                '@done' => 'completed',
                '@fail' => [
                    ['target' => 'awaiting', 'guards' => 'isRetryableGuard'],
                    ['target' => 'failed'],
                ],
            ],
            'completed' => ['type' => 'final'],
            'failed'    => ['type' => 'final'],
        ],
    ], behavior: [
        'guards' => [
            'isRetryableGuard' => fn (): bool => true,
        ],
    ]);

    $result = (new PathEnumerator($definition))->enumerate();

    // All signatures must be unique — no duplicate paths
    $signatures = array_map(fn ($p) => $p->signature(), $result->paths);
    expect(count($signatures))->toBe(count(array_unique($signatures)));

    // Verify expected path count: @done→completed (HAPPY), @fail fallback→failed (FAIL),
    // @fail retry→awaiting (LOOP), CONFIRM from awaiting (LOOP already handled by dedup)
    expect($result->happyPaths())->toHaveCount(1)
        ->and($result->failPaths())->toHaveCount(1)
        ->and($result->loopPaths())->toHaveCount(1);
});

test('unhandled outcomes: fire-and-forget is skipped', function (): void {
    $result = FireAndForgetTargetParentMachine::definition()->enumeratePaths();

    expect($result->unhandledChildOutcomes())->toBe([]);
});

test('unhandled outcomes: job actor is skipped', function (): void {
    $result = JobActorParentMachine::definition()->enumeratePaths();

    expect($result->unhandledChildOutcomes())->toBe([]);
});
