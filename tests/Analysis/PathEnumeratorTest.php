<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Analysis\PathType;
use Tarfinlabs\EventMachine\Analysis\PathEnumerator;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AbcMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\GuardedMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysGuardMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ConditionalOnDoneMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Compound\ConditionalCompoundOnDoneMachine;

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
