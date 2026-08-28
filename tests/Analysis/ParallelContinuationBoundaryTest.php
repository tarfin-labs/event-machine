<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Analysis\PathType;
use Tarfinlabs\EventMachine\Analysis\MachinePath;
use Tarfinlabs\EventMachine\Analysis\PathEnumerator;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Analysis\ParallelPathGroup;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Jobs\ProcessJob;

/**
 * @return list<MachinePath>
 */
function regionPathsFor(MachineDefinition $definition, string $regionKey): array
{
    $result = (new PathEnumerator($definition))->enumerate();

    foreach ($result->parallelGroups as $group) {
        if (isset($group->regionPaths[$regionKey])) {
            return $group->regionPaths[$regionKey];
        }
    }

    return [];
}

/**
 * @return list<string>
 */
function typeValues(array $paths): array
{
    return array_map(static fn (MachinePath $p): string => $p->type->value, $paths);
}

// ── An @always on the parallel state itself ──────────────────────────────────

test('an @always declared on a parallel state is followed, not swallowed', function (): void {
    // This regressed: handleParallel counted the @always as an outcome, so no DEAD_END
    // was recorded, while enumerateTransitions skipped it because isAlways is set — the
    // state, its regions and everything downstream vanished with no truncation flag.
    $definition = MachineDefinition::define(config: [
        'id'      => 'always_on_parallel',
        'initial' => 'idle',
        'states'  => [
            'idle'    => ['on' => ['START' => 'working']],
            'working' => [
                'type'   => 'parallel',
                'on'     => ['@always' => 'done_state'],
                'states' => [
                    'alpha' => [
                        'initial' => 'a1',
                        'states'  => ['a1' => ['on' => ['N' => 'a2']], 'a2' => ['type' => 'final']],
                    ],
                ],
            ],
            'done_state' => ['type' => 'final'],
        ],
    ]);

    $result = (new PathEnumerator($definition))->enumerate();

    expect($result->paths)->toHaveCount(1)
        ->and($result->paths[0]->type)->toBe(PathType::HAPPY)
        ->and($result->paths[0]->signature())->toBe('idle→[START]→working→[@always]→done_state')
        ->and($result->analysisTruncated())->toBeFalse();
});

test('a region keeps its own transitions when it inherits an escaping @always', function (): void {
    // handleAlwaysPriority took its unguarded-fallback early return even when the
    // boundary had skipped every branch, so the region state's own in-region transition
    // was dropped. The @always did not actually fire, so the rest is still reachable.
    $definition = MachineDefinition::define(config: [
        'id'      => 'always_on_parallel',
        'initial' => 'idle',
        'states'  => [
            'idle'    => ['on' => ['START' => 'working']],
            'working' => [
                'type'   => 'parallel',
                'on'     => ['@always' => 'done_state'],
                'states' => [
                    'alpha' => [
                        'initial' => 'a1',
                        'states'  => ['a1' => ['on' => ['N' => 'a2']], 'a2' => ['type' => 'final']],
                    ],
                ],
            ],
            'done_state' => ['type' => 'final'],
        ],
    ]);

    $paths = regionPathsFor($definition, 'alpha');

    expect($paths)->toHaveCount(1)
        ->and($paths[0]->type)->toBe(PathType::HAPPY)
        ->and($paths[0]->signature())->toBe('a1→[N]→a2');
});

// ── The boundary applies to every continuation the enumerator follows ────────

test('the boundary applies to a compound @done continuation inside a region', function (): void {
    // handleFinal follows a FINAL state up to its enclosing compound's @done. Leaving
    // that out of the boundary would let region enumeration escape and reopen the crash
    // path, so it is recorded as a region exit rather than followed.
    $definition = MachineDefinition::define(config: [
        'id'      => 'compound_done_escape',
        'initial' => 'idle',
        'states'  => [
            'idle'    => ['on' => ['START' => 'working']],
            'working' => [
                'type'   => 'parallel',
                'on'     => ['ABORT' => 'aborted'],
                'states' => [
                    'alpha' => [
                        'initial' => 'inner',
                        'states'  => [
                            'inner' => [
                                '@done'   => 'escaped',
                                'initial' => 'finishing',
                                'states'  => ['finishing' => ['type' => 'final']],
                            ],
                        ],
                    ],
                ],
            ],
            'escaped' => ['type' => 'final'],
            'aborted' => ['type' => 'final'],
        ],
    ]);

    $paths = regionPathsFor($definition, 'alpha');

    expect(typeValues($paths))->toContain('region_exit');

    $exit = array_values(array_filter($paths, static fn (MachinePath $p): bool => $p->type === PathType::REGION_EXIT))[0];

    expect($exit->signature())->toContain('[@done]')
        ->and($exit->signature())->toContain('escaped');
});

test('the boundary applies to an invoke @done leaving a region', function (): void {
    $definition = MachineDefinition::define(config: [
        'id'      => 'invoke_escape',
        'initial' => 'idle',
        'states'  => [
            'idle'    => ['on' => ['START' => 'working']],
            'working' => [
                'type'   => 'parallel',
                'on'     => ['ABORT' => 'aborted'],
                'states' => [
                    'alpha' => [
                        'initial' => 'delegating',
                        'states'  => [
                            'delegating' => [
                                'job'   => ProcessJob::class,
                                '@done' => 'escaped',
                                '@fail' => 'failed_outside',
                            ],
                        ],
                    ],
                ],
            ],
            'escaped'        => ['type' => 'final'],
            'failed_outside' => ['type' => 'final'],
            'aborted'        => ['type' => 'final'],
        ],
    ]);

    $paths = regionPathsFor($definition, 'alpha');

    // Both invoke outcomes leave the region, so both are recorded rather than followed.
    $signatures = array_map(static fn (MachinePath $p): string => $p->signature(), $paths);

    expect(typeValues($paths))->each->toBe('region_exit')
        ->and($paths)->toHaveCount(2)
        ->and(implode(' | ', $signatures))->toContain('[@done]')
        ->and(implode(' | ', $signatures))->toContain('[@fail]');
});

// ── What lies beyond a region-declared escape ───────────────────────────────

test('everything past a region-declared escape is enumerated', function (): void {
    // Recording the exit made the edge visible; the subtree beyond its target stayed
    // invisible, with no truncation flag to show for it. A transition declared inside a
    // region has no machine-level representation of its own, so nothing else would have
    // reached 'review', let alone 'resolved'.
    $definition = MachineDefinition::define(config: [
        'id'      => 'downstream_probe',
        'initial' => 'idle',
        'states'  => [
            'idle'    => ['on' => ['START' => 'working']],
            'working' => [
                'type'   => 'parallel',
                '@done'  => 'finished',
                'states' => [
                    'alpha' => [
                        'initial' => 'a1',
                        'states'  => ['a1' => ['on' => ['ESCALATE' => 'review']]],
                    ],
                ],
            ],
            'review'   => ['on' => ['RESOLVE' => 'resolved']],
            'resolved' => ['type' => 'final'],
            'finished' => ['type' => 'final'],
        ],
    ]);

    $result     = (new PathEnumerator($definition))->enumerate();
    $signatures = array_map(static fn (MachinePath $p): string => $p->signature(), $result->paths);

    expect($signatures)->toContain('idle→[START]→working→[ESCALATE]→review→[RESOLVE]→resolved')
        ->and($result->analysisTruncated())->toBeFalse();

    // The region still records the edge itself, so both levels tell the same story.
    expect(typeValues(regionPathsFor($definition, 'alpha')))->toContain('region_exit');
});

test('two regions escaping to the same target follow it once', function (): void {
    $definition = MachineDefinition::define(config: [
        'id'      => 'shared_escape_probe',
        'initial' => 'idle',
        'states'  => [
            'idle'    => ['on' => ['START' => 'working']],
            'working' => [
                'type'   => 'parallel',
                '@done'  => 'finished',
                'states' => [
                    'alpha' => ['initial' => 'a1', 'states' => ['a1' => ['on' => ['ESCALATE' => 'review']]]],
                    'beta'  => ['initial' => 'b1', 'states' => ['b1' => ['on' => ['ESCALATE' => 'review']]]],
                ],
            ],
            'review'   => ['type' => 'final'],
            'finished' => ['type' => 'final'],
        ],
    ]);

    $result = (new PathEnumerator($definition))->enumerate();

    $toReview = array_filter(
        $result->paths,
        static fn (MachinePath $p): bool => str_contains($p->signature(), 'review'),
    );

    expect($toReview)->toHaveCount(1);
});

// ── An @always never enumerates the remaining transitions twice ──────────────

test('a guarded @always on a parallel state does not double-enumerate', function (): void {
    // handleParallel enumerated the own transitions, then handed the same array to
    // handleAlwaysPriority, whose guard-fail arm enumerated them again.
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'double_probe',
            'initial' => 'idle',
            'states'  => [
                'idle'    => ['on' => ['START' => 'working']],
                'working' => [
                    'type' => 'parallel',
                    'on'   => [
                        '@always' => [['guards' => 'neverGuard', 'target' => 'gated']],
                        'SKIP'    => 'skipped',
                    ],
                    'states' => [
                        'alpha' => ['initial' => 'a1', 'states' => ['a1' => ['on' => ['AD' => 'a2']], 'a2' => ['type' => 'final']]],
                    ],
                ],
                'gated'   => ['type' => 'final'],
                'skipped' => ['type' => 'final'],
            ],
        ],
        behavior: ['guards' => ['neverGuard' => fn (): bool => false]],
    );

    $result     = (new PathEnumerator($definition))->enumerate();
    $signatures = array_map(static fn (MachinePath $p): string => $p->signature(), $result->paths);

    expect($result->paths)->toHaveCount(2)
        ->and(array_count_values($signatures)['idle→[START]→working→[SKIP]→skipped'])->toBe(1);
});

// ── The unbounded enumerator is untouched by any of it ───────────────────────

test('no region outcome can be recorded without a boundary', function (): void {
    // Every boundary rule is gated on $boundary being set, and only handleParallel ever
    // sets it. A machine with inherited transitions but no parallel state must therefore
    // follow all of them and produce none of the two region-only types.
    $definition = MachineDefinition::define(config: [
        'id'      => 'no_parallel_anywhere',
        'initial' => 'outer',
        'states'  => [
            'outer' => [
                'on'      => ['ABORT' => 'aborted'],
                'initial' => 'inner',
                'states'  => [
                    'inner' => ['on' => ['NEXT' => 'settled']],
                ],
            ],
            'settled' => ['type' => 'final'],
            'aborted' => ['type' => 'final'],
        ],
    ]);

    $result = (new PathEnumerator($definition))->enumerate();

    $types = typeValues($result->paths);

    // The inherited ABORT is followed rather than deferred, because there is no level
    // above to defer it to.
    expect($result->paths)->not->toBeEmpty()
        ->and($types)->not->toContain('region_exit')
        ->and($types)->not->toContain('region_deferred')
        ->and(implode(' | ', array_map(static fn (MachinePath $p): string => $p->signature(), $result->paths)))
        ->toContain('[ABORT]');
});

// ── A followed escape is an outcome, even though it is not a structural one ───

test('a parallel continued only by a region escape is not also a dead end', function (): void {
    // The dead-end test was purely structural — @done, @fail, own transitions, @always —
    // so once escapes became followable, a parallel state whose ONLY continuation is an
    // escape declared inside a region recorded both the followed path and a contradictory
    // dead end for that same state. Every other escape test in this file gives its
    // parallel state a @done, which satisfies the structural test and masks the gate;
    // this one deliberately gives it none.
    $definition = MachineDefinition::define(config: [
        'id'      => 'escape_only_continuation',
        'initial' => 'idle',
        'states'  => [
            'idle' => ['on' => ['START' => 'work']],
            'work' => [
                'type'   => 'parallel',
                'states' => [
                    'alpha' => [
                        'initial' => 'a1',
                        'states'  => ['a1' => ['on' => ['ESCALATE' => 'review']]],
                    ],
                ],
            ],
            'review'   => ['on' => ['RESOLVE' => 'resolved']],
            'resolved' => ['type' => 'final'],
        ],
    ]);

    $result = (new PathEnumerator($definition))->enumerate();

    expect(typeValues($result->paths))->not->toContain('dead_end')
        ->and($result->paths)->toHaveCount(1)
        ->and($result->paths[0]->signature())->toContain('[ESCALATE]')
        ->and($result->paths[0]->signature())->toContain('resolved');
});

// ── Following an escape still stops at the enclosing region's boundary ───────

test('a nested parallel escape does not carry the region walk out of its region', function (): void {
    // A NESTED parallel's escape target lies outside the enclosing region. Following it
    // without the boundary check let the region sub-enumerator resume walking the machine
    // at large and re-enumerate every parallel it reached, each on a budget of its own.
    // Work then doubled per nesting level while the top-level path count stayed flat, so
    // neither ceiling ever tripped and analysisTruncated() still reported a complete
    // analysis — the same unbounded walk this class exists to prevent, only quieter.
    $definition = MachineDefinition::define(config: [
        'id'      => 'nested_escape_boundary',
        'initial' => 'outer',
        'states'  => [
            'outer' => [
                'type'   => 'parallel',
                'states' => [
                    'ra' => [
                        'initial' => 'inner',
                        'states'  => [
                            'inner' => [
                                'type'   => 'parallel',
                                'states' => [
                                    'rb' => [
                                        'initial' => 'deep',
                                        'states'  => ['deep' => ['on' => ['OUT' => 'faraway']]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'rc' => ['initial' => 'y', 'states' => ['y' => ['type' => 'final']]],
                ],
            ],
            'faraway' => ['on' => ['NEXT' => 'sink']],
            'sink'    => ['type' => 'final'],
        ],
    ]);

    $raPaths = regionPathsFor($definition, 'ra');

    expect($raPaths)->toHaveCount(1)
        ->and($raPaths[0]->type)->toBe(PathType::REGION_EXIT);

    // A region exit names its target as the path's LAST step — that is how the edge out
    // is represented — but nothing beyond that target belongs to the region.
    $steps = $raPaths[0]->steps;

    expect($steps[count($steps) - 1]->stateId)->toContain('faraway');

    // A belt-and-braces guard rather than the assertion that catches the regression: with
    // the boundary check removed the walk is stopped one hop later by the other
    // leavesBoundary() test inside enumerateTransitions, so 'sink' never reaches a region
    // path either way. What actually fails then is the REGION_EXIT type above (it becomes
    // REGION_DEFERRED) and the machine-level expectation below.
    foreach ($raPaths as $path) {
        expect($path->signature())->not->toContain('sink');
    }

    // The escape is still followed where it belongs: at machine level, from the parallel.
    $result = (new PathEnumerator($definition))->enumerate();

    expect(implode(' | ', array_map(static fn (MachinePath $p): string => $p->signature(), $result->paths)))
        ->toContain('sink');
});

// ── The two continuation arms must reach the same verdict ────────────────────

test('an inherited plain event defers a region exactly as an inherited @always does', function (): void {
    // handleParallel picks one of two arms for the parallel's own continuations, and they
    // disagreed. The @always arm snapshots $this->deferrals around the call; the plain
    // arm kept only enumerateTransitions()'s bool and dropped the callee's local
    // $deferred. Same machine, one key changed: with @always the region recorded
    // REGION_DEFERRED, with a plain event it recorded nothing at all — and the nested
    // parallel with its own region vanished from the result too, analysisTruncated()
    // still reporting a complete analysis.
    $build = static fn (string $event): MachineDefinition => MachineDefinition::define(config: [
        'id'      => 'deferral_symmetry',
        'initial' => 'par',
        'states'  => [
            'par' => [
                'type'   => 'parallel',
                'on'     => [$event => 'settled'],
                'states' => [
                    'ra' => [
                        'initial' => 'inner',
                        'states'  => [
                            'inner' => [
                                'type'   => 'parallel',
                                'states' => [
                                    'rb' => [
                                        'initial' => 'x',
                                        'states'  => ['x' => ['on' => ['E' => 'y']], 'y' => ['type' => 'final']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'settled' => ['type' => 'final'],
        ],
    ]);

    foreach (['@always', 'GO'] as $event) {
        $definition = $build($event);

        // The region's only continuation is declared on the parallel state, so machine
        // level owns it: deferred, never a dead end.
        $regionPaths = regionPathsFor($definition, 'ra');

        expect($regionPaths)->toHaveCount(1, "region ra under {$event}")
            ->and($regionPaths[0]->type)->toBe(PathType::REGION_DEFERRED, "region ra under {$event}");

        // And the parallel nested inside that region is still analysed and reported.
        expect(regionPathsFor($definition, 'rb'))->toHaveCount(1, "nested region rb under {$event}");

        expect((new PathEnumerator($definition))->enumerate()->analysisTruncated())
            ->toBeFalse("truncation flag under {$event}");
    }
});

// ── A parallel inside a region is still part of the result ───────────────────

test('a parallel nested inside a region is reported as its own group', function (): void {
    // Region sub-enumerators record their own parallelGroups, and handleParallel used to
    // harvest only their paths and truncation flags. The nested parallel and its regions
    // were dropped, so machine:paths counted parallel states from the definition and
    // printed groups from the enumeration and the two lines disagreed — while
    // analysisTruncated() still claimed the analysis was complete.
    $definition = MachineDefinition::define(config: [
        'id'      => 'nested_group_reporting',
        'initial' => 'par',
        'states'  => [
            'par' => [
                'type'   => 'parallel',
                'on'     => ['ESC' => 'settled'],
                'states' => [
                    'ra' => [
                        'initial' => 'inner',
                        'states'  => [
                            'inner' => [
                                'type'   => 'parallel',
                                'states' => [
                                    'rb' => [
                                        'initial' => 'x',
                                        'states'  => ['x' => ['on' => ['E' => 'y']], 'y' => ['type' => 'final']],
                                    ],
                                    'rc' => [
                                        'initial' => 'z',
                                        'states'  => ['z' => ['on' => ['F' => 'w']], 'w' => ['type' => 'final']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'settled' => ['type' => 'final'],
        ],
    ]);

    $result = (new PathEnumerator($definition))->enumerate();

    // Two parallel states are defined, so two groups must be reported.
    $parallelStateIds = [];

    foreach ($definition->idMap as $id => $state) {
        if ($state->type === StateDefinitionType::PARALLEL) {
            $parallelStateIds[] = $id;
        }
    }

    $groupIds = array_map(
        static fn (ParallelPathGroup $g): string => $g->parallelStateId,
        $result->parallelGroups,
    );

    sort($parallelStateIds);
    sort($groupIds);

    expect($groupIds)->toBe($parallelStateIds)
        ->and($result->analysisTruncated())->toBeFalse();

    // Both of the nested parallel's regions are present, not just the outer one.
    expect(regionPathsFor($definition, 'rb'))->toHaveCount(1)
        ->and(regionPathsFor($definition, 'rc'))->toHaveCount(1);
});

// ── One group per parallel state, however many routes reach it ───────────────

test('a parallel reached by two routes is still recorded as one group', function (): void {
    // Merging a region sub-enumerator's groups into the parent's without deduplicating
    // recorded the same parallel state twice: a sub-enumerator is a fresh object and its
    // own already-recorded scan cannot see what the caller holds. The two copies could
    // disagree — the second ran on whatever budget was left — and under nesting the
    // retained groups grew exponentially, all while the result called itself complete.
    //
    // Here a transition declared inside q1's region jumps straight into a state nested
    // in q2, so the parallel at q2.rc.deep is reached both through that escape and by
    // machine-level enumeration of q2.
    $definition = MachineDefinition::define(config: [
        'id'      => 'two_routes_one_group',
        'initial' => 'q1',
        'states'  => [
            'q1' => [
                'type'   => 'parallel',
                'on'     => ['NEXT' => 'q2'],
                'states' => [
                    'ra' => [
                        'initial' => 'a',
                        'states'  => ['a' => ['on' => ['JUMP' => 'q2.rc.deep']]],
                    ],
                ],
            ],
            'q2' => [
                'type'   => 'parallel',
                'states' => [
                    'rc' => [
                        'initial' => 'deep',
                        'states'  => [
                            'deep' => [
                                'type'   => 'parallel',
                                'states' => [
                                    'rd' => [
                                        'initial' => 'z',
                                        'states'  => ['z' => ['on' => ['F' => 'w']], 'w' => ['type' => 'final']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $result = (new PathEnumerator($definition))->enumerate();

    $groupIds = array_map(
        static fn (ParallelPathGroup $g): string => $g->parallelStateId,
        $result->parallelGroups,
    );

    $duplicated = array_keys(array_filter(
        array_count_values($groupIds),
        static fn (int $count): bool => $count > 1,
    ));

    expect($duplicated)->toBe([]);

    // And the count still agrees with the definition, which is the contradiction a reader
    // of `machine:paths` would otherwise see between its two summary lines.
    $parallelStateIds = [];

    foreach ($definition->idMap as $id => $state) {
        if ($state->type === StateDefinitionType::PARALLEL) {
            $parallelStateIds[] = $id;
        }
    }

    sort($parallelStateIds);
    sort($groupIds);

    expect($groupIds)->toBe($parallelStateIds);
});
