<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Analysis\MachinePath;
use Tarfinlabs\EventMachine\Analysis\MachineGraph;
use Tarfinlabs\EventMachine\Analysis\PathEnumerator;
use Tarfinlabs\EventMachine\Analysis\PathCoverageReport;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Analysis\ScenarioPathResolver;
use Tarfinlabs\EventMachine\Analysis\PathEnumerationResult;
use Tarfinlabs\EventMachine\Exceptions\NoScenarioPathFoundException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;

/**
 * A chain long enough that a low ceiling cuts it, and short enough to stay readable.
 */
function chainDefinition(): MachineDefinition
{
    return MachineDefinition::define(config: [
        'id'      => 'chain_probe',
        'initial' => 'idle',
        'states'  => [
            'idle' => ['on' => ['N' => 'a']],
            'a'    => ['on' => ['N' => 'b']],
            'b'    => ['on' => ['N' => 'c']],
            'c'    => ['type' => 'final'],
        ],
    ]);
}

test('a truncated path is excluded from the coverage denominator', function (): void {
    $definition = chainDefinition();

    $complete = (new PathEnumerator($definition))->enumerate();
    $cut      = (new PathEnumerator($definition, 1000, null, 2))->enumerate();

    expect($complete->analysisTruncated())->toBeFalse()
        ->and($cut->analysisTruncated())->toBeTrue()
        ->and($cut->truncatedPaths())->not->toBeEmpty();

    // Observe nothing at all. The complete enumeration has one real path to miss;
    // the cut one has only a truncated path, which no run could ever match, so it
    // must not sit in the denominator making 100% unreachable.
    $completeReport = new PathCoverageReport($complete, []);
    $cutReport      = new PathCoverageReport($cut, []);

    expect($completeReport->uncoveredPaths())->toHaveCount(1)
        ->and($cutReport->uncoveredPaths())->toHaveCount(0)
        ->and($cutReport->skippedPathCount())->toBe(count($cut->paths));
});

test('the coverage report discloses that its enumeration was cut short', function (): void {
    $definition = chainDefinition();

    $complete = new PathCoverageReport((new PathEnumerator($definition))->enumerate(), []);
    $cut      = new PathCoverageReport((new PathEnumerator($definition, 1000, null, 2))->enumerate(), []);

    // Disclosure only: the percentage still reads well, which is exactly why the flag
    // has to exist — a gate reading the number alone would pass over a partial analysis.
    expect($complete->enumerationTruncated())->toBeFalse()
        ->and($cut->enumerationTruncated())->toBeTrue()
        ->and($cut->coveragePercentage())->toBe(100.0);
});

test('a region that truncates raises the flag on the top-level result', function (): void {
    // The region sub-enumerator's flags used to be discarded along with the enumerator,
    // so a region cut short still reported a complete analysis. This covers the DEPTH
    // half. The path half has no test on purpose: the shared budget makes the parent's
    // own gate fire first in every case, so removing that arm changes no flag on any of
    // 2400 fuzzed (shape, budget) pairs. The comment at its site records that rather
    // than this test pretending to cover both.
    $definition = MachineDefinition::define(config: [
        'id'      => 'region_truncation_probe',
        'initial' => 'idle',
        'states'  => [
            'idle'    => ['on' => ['START' => 'working']],
            'working' => [
                'type'   => 'parallel',
                '@done'  => 'finished',
                'states' => [
                    'alpha' => [
                        'initial' => 'a1',
                        'states'  => [
                            'a1' => ['on' => ['N' => 'a2']],
                            'a2' => ['on' => ['N' => 'a3']],
                            'a3' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'finished' => ['type' => 'final'],
        ],
    ]);

    // Deep enough for the machine-level path, too shallow for the region beneath it:
    // the parallel state sits at depth 2, so the region enumerator starts with that
    // offset and its own two steps cross the ceiling.
    $result = (new PathEnumerator($definition, 1000, null, 3))->enumerate();

    $regionPaths = [];

    foreach ($result->parallelGroups as $group) {
        foreach ($group->regionPaths as $paths) {
            $regionPaths = [...$regionPaths, ...$paths];
        }
    }

    $regionTypes = array_map(static fn (MachinePath $path): string => $path->type->value, $regionPaths);

    expect($regionTypes)->toContain('truncated')
        ->and($result->depthLimitReached)->toBeTrue()
        ->and($result->analysisTruncated())->toBeTrue();
});

test('an exhausted scenario search is not reported as truncated', function (): void {
    $resolver = new ScenarioPathResolver(new MachineGraph(ScenarioTestMachine::definition()));

    $paths = $resolver->resolveAll('reviewing', 'APPROVE', 'blocked');

    expect($paths)->toBeEmpty()
        ->and($resolver->wasTruncated())->toBeFalse();
});

test('a capped scenario search reports truncation rather than absence', function (): void {
    $resolver = new ScenarioPathResolver(new MachineGraph(ScenarioTestMachine::definition()), maxIterations: 1);

    $paths = $resolver->resolveAll('reviewing', 'START_PARALLEL', 'blocked');

    expect($paths)->toBeEmpty()
        ->and($resolver->wasTruncated())->toBeTrue();
});

test('resolve throws a different exception for a truncated search', function (): void {
    $graph = new MachineGraph(ScenarioTestMachine::definition());

    $exhausted = null;

    try {
        (new ScenarioPathResolver($graph))->resolve('reviewing', 'APPROVE', 'blocked');
    } catch (NoScenarioPathFoundException $e) {
        $exhausted = $e->getMessage();
    }

    $truncated = null;

    try {
        (new ScenarioPathResolver($graph, maxIterations: 1))->resolve('reviewing', 'START_PARALLEL', 'blocked');
    } catch (NoScenarioPathFoundException $e) {
        $truncated = $e->getMessage();
    }

    expect($exhausted)->toContain('No path from')
        ->and($truncated)->toContain('truncated at the search limit')
        ->and($truncated)->toContain('A path may still exist')
        ->and($truncated)->not->toBe($exhausted);
});

test('the truncation flag describes the latest resolution only', function (): void {
    $resolver = new ScenarioPathResolver(new MachineGraph(ScenarioTestMachine::definition()), maxIterations: 1);

    $resolver->resolveAll('reviewing', 'START_PARALLEL', 'blocked');
    expect($resolver->wasTruncated())->toBeTrue();

    // A resolution that completes must clear it, or every later caller inherits a
    // stale warning from an unrelated search.
    $resolver->resolveAll('reviewing', 'APPROVE', 'approved');
    expect($resolver->wasTruncated())->toBeFalse();
});

test('the path ceiling bounds region paths too, not each region on its own', function (): void {
    // Region paths are handed to ParallelPathGroup rather than to the enumerator's own
    // $paths, so a budget computed from $paths alone was re-issued near-full to every
    // region at every nesting level. A result could then carry many times maxPaths while
    // pathLimitReached stayed false and the analysis reported itself complete.
    $regions = [];

    foreach (['ra', 'rb'] as $regionKey) {
        $branches = [];
        $fan      = [];

        for ($j = 0; $j < 6; $j++) {
            $branches['leaf'.$j] = ['type' => 'final'];
            $fan['E'.$j]         = 'leaf'.$j;
        }

        $branches['fan']     = ['on' => $fan];
        $regions[$regionKey] = ['initial' => 'fan', 'states' => $branches];
    }

    $definition = MachineDefinition::define(config: [
        'id'      => 'region_budget_probe',
        'initial' => 'idle',
        'states'  => [
            'idle'     => ['on' => ['START' => 'working']],
            'working'  => ['type' => 'parallel', 'states' => $regions, 'on' => ['NEXT' => 'finished']],
            'finished' => ['type' => 'final'],
        ],
    ]);

    // Unbounded: 6 branches per region, both regions enumerated in full.
    $complete    = (new PathEnumerator($definition))->enumerate();
    $completeAll = count($complete->paths);

    foreach ($complete->parallelGroups as $group) {
        foreach ($group->regionPaths as $paths) {
            $completeAll += count($paths);
        }
    }

    expect($completeAll)->toBeGreaterThan(10)
        ->and($complete->analysisTruncated())->toBeFalse();

    // Bounded at 10: the ceiling must cover machine paths and region paths together.
    $cut      = (new PathEnumerator($definition, 10))->enumerate();
    $recorded = count($cut->paths);

    foreach ($cut->parallelGroups as $group) {
        foreach ($group->regionPaths as $paths) {
            $recorded += count($paths);
        }
    }

    expect($recorded)->toBeLessThanOrEqual(10)
        ->and($cut->pathLimitReached)->toBeTrue()
        ->and($cut->analysisTruncated())->toBeTrue();
});

test('machine paths cannot spend the ceiling a second time after the regions', function (): void {
    // Charging region paths to $subPathsRecorded bounded what the REGIONS spent, but
    // recordPath still gated on count($paths) alone. Regions could therefore consume the
    // whole budget and machine level then record maxPaths more on top — twice the
    // ceiling, with pathLimitReached false because neither half reached it by itself.
    // The earlier ceiling test does not catch this: its numbers happen to trip the flag.
    $definition = MachineDefinition::define(config: [
        'id'      => 'double_spend_probe',
        'initial' => 'idle',
        'states'  => [
            'idle' => ['on' => ['START' => 'work']],
            'work' => [
                'type'   => 'parallel',
                '@done'  => 'after',
                'states' => [
                    'ra' => [
                        'initial' => 'fan',
                        'states'  => [
                            'fan' => ['on' => ['E0' => 'l0', 'E1' => 'l1']],
                            'l0'  => ['type' => 'final'],
                            'l1'  => ['type' => 'final'],
                        ],
                    ],
                    'rb' => [
                        'initial' => 'fan',
                        'states'  => [
                            'fan' => ['on' => ['F0' => 'm0', 'F1' => 'm1']],
                            'm0'  => ['type' => 'final'],
                            'm1'  => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            // Three continuations past the parallel, so machine level has real work left
            // once the regions have already spent the whole budget.
            'after' => ['on' => ['G0' => 'end0', 'G1' => 'end1', 'G2' => 'end2']],
            'end0'  => ['type' => 'final'],
            'end1'  => ['type' => 'final'],
            'end2'  => ['type' => 'final'],
        ],
    ]);

    $countAll = static function (int $budget) use ($definition): array {
        $result = (new PathEnumerator($definition, $budget))->enumerate();
        $total  = count($result->paths);

        foreach ($result->parallelGroups as $group) {
            foreach ($group->regionPaths as $paths) {
                $total += count($paths);
            }
        }

        return [$total, $result];
    };

    // Unbounded: 4 region paths and 3 machine paths, so a budget of 4 is genuinely
    // exceeded by the regions alone and leaves machine-level work pending.
    [$uncapped] = $countAll(1000);

    expect($uncapped)->toBe(7);

    [$capped, $result] = $countAll(4);

    expect($capped)->toBeLessThanOrEqual(4)
        ->and($result->pathLimitReached)->toBeTrue()
        ->and($result->analysisTruncated())->toBeTrue();
});

test('enumerating twice on one instance gives the same answer both times', function (): void {
    // enumerate() reinitialises the mutable fields so an instance can be reused, but
    // $subPathsRecorded was added later and missed that list. Since recordPath() gates on
    // totalRecorded(), a second run started with the first run's spend already counted:
    // it would record nothing and declare the path ceiling reached. Every call site today
    // builds a fresh enumerator, so this was latent — which is exactly why it needs a test
    // rather than an argument.
    $definition = MachineDefinition::define(config: [
        'id'      => 'reuse_probe',
        'initial' => 'idle',
        'states'  => [
            'idle' => ['on' => ['START' => 'work']],
            'work' => [
                'type'   => 'parallel',
                '@done'  => 'settled',
                'states' => [
                    'ra' => [
                        'initial' => 'fan',
                        'states'  => [
                            'fan' => ['on' => ['E0' => 'l0', 'E1' => 'l1']],
                            'l0'  => ['type' => 'final'],
                            'l1'  => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'settled' => ['type' => 'final'],
        ],
    ]);

    // One run records 3 paths: 2 in region ra, 1 at machine level. A ceiling of 4 admits
    // one run and not two, so a leaked spend shows up as the second run being truncated.
    // At the default 1000 the leak is invisible on a machine this size — the ceiling has
    // to be close enough for the carried-over count to cross it.
    $enumerator = new PathEnumerator($definition, 4);

    $summarise = static function (PathEnumerationResult $result): array {
        $regions = 0;

        foreach ($result->parallelGroups as $group) {
            foreach ($group->regionPaths as $paths) {
                $regions += count($paths);
            }
        }

        return [
            'paths'     => count($result->paths),
            'regions'   => $regions,
            'groups'    => count($result->parallelGroups),
            'truncated' => $result->analysisTruncated(),
        ];
    };

    $first  = $summarise($enumerator->enumerate());
    $second = $summarise($enumerator->enumerate());

    expect($first['paths'])->toBeGreaterThan(0)
        ->and($first['regions'])->toBeGreaterThan(0)
        ->and($first['truncated'])->toBeFalse()
        ->and($second)->toBe($first);
});

test('the ceiling is not charged for region work the result discards', function (): void {
    // A region sub-enumerator used to re-analyse a parallel its caller had already
    // analysed; the dedup then threw the duplicate away, but the budget had been spent.
    // So the smallest ceiling that admitted a complete analysis grew exponentially with
    // nesting while what the result kept grew linearly — a 44-state machine tripped the
    // default 1000, and raising it re-opened work that doubled per level with every
    // truncation flag still false. Seeding each sub-enumerator with the groups its caller
    // holds means the work is never done twice, so budget and result move together.
    // The shape matters: `a` offers TWO routes, one straight into the nested parallel and
    // one to the parallel enclosing it. Without that, every parallel is reachable one way
    // only, the dedup never fires, and no work is discarded — a nested chain alone does
    // not reproduce this at all.
    $level = static function (int $i) use (&$level): array {
        if ($i === 0) {
            return [
                'initial' => 'x',
                'states'  => ['x' => ['on' => ['E' => 'y']], 'y' => ['type' => 'final']],
            ];
        }

        return [
            'initial' => 'a',
            'states'  => [
                'a' => ['on' => ['B' => 'b.rb.p', 'A' => 'b']],
                'b' => [
                    'type'   => 'parallel',
                    'states' => [
                        'rb' => [
                            'initial' => 'p',
                            'states'  => [
                                'p' => ['type' => 'parallel', 'states' => ['rp' => $level($i - 1)]],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    };

    $build = static fn (int $depth): MachineDefinition => MachineDefinition::define(config: [
        'id'      => 'ceiling_charge_probe',
        'initial' => 'root',
        'states'  => ['root' => ['type' => 'compound'] + $level($depth)],
    ]);

    $retainedOf = static function (PathEnumerationResult $result): int {
        $total = count($result->paths);

        foreach ($result->parallelGroups as $group) {
            foreach ($group->regionPaths as $paths) {
                $total += count($paths);
            }
        }

        return $total;
    };

    foreach ([2, 5, 8] as $depth) {
        $definition = $build($depth);

        $complete = (new PathEnumerator($definition))->enumerate();
        $retained = $retainedOf($complete);

        expect($complete->analysisTruncated())->toBeFalse("depth {$depth} unbounded");

        // Exactly the retained count must suffice. Charging for discarded work made the
        // required budget grow as a power of the nesting depth instead.
        $atExactly = (new PathEnumerator($definition, $retained))->enumerate();

        expect($atExactly->analysisTruncated())->toBeFalse("depth {$depth} at maxPaths={$retained}")
            ->and($retainedOf($atExactly))->toBe($retained, "depth {$depth} at maxPaths={$retained}");

        // And one less must not, or the ceiling would not be binding at all.
        $justUnder = (new PathEnumerator($definition, $retained - 1))->enumerate();

        expect($justUnder->analysisTruncated())->toBeTrue("depth {$depth} at maxPaths=".($retained - 1));
    }
});

test('a region truncation is disclosed even when the parent records nothing', function (): void {
    // handleParallel propagates two region flags. The depth half is covered above; this is
    // the path half, and finding a shape for it took three attempts. The shared budget
    // normally makes the parent's own recordPath refuse first, which raises the flag
    // without the propagation — so a plain region, and then a nested one, both left the
    // mutation alive, and a 2400-case sweep wrongly concluded the line was unreachable.
    //
    // The shape that isolates it: a parallel whose @done branch has NO target. The @done
    // loop skips the null target so nothing is enumerated, while the state still counts as
    // having an outcome, so neither a continuation nor a dead end is recorded. No
    // machine-level recordPath runs at any budget — verified below — and propagating the
    // region's flag is the only way the result can admit it was cut short.
    $regions = [];

    foreach (['ra', 'rb'] as $regionKey) {
        $branches = [];
        $fan      = [];

        for ($j = 0; $j < 5; $j++) {
            $branches['leaf'.$j] = ['type' => 'final'];
            $fan['E'.$j]         = 'leaf'.$j;
        }

        $branches['fan']     = ['on' => $fan];
        $regions[$regionKey] = ['initial' => 'fan', 'states' => $branches];
    }

    $definition = MachineDefinition::define(config: [
        'id'      => 'targetless_done_probe',
        'initial' => 'idle',
        'states'  => [
            'idle' => ['on' => ['START' => 'work']],
            'work' => [
                'type'   => 'parallel',
                '@done'  => [['actions' => 'noteAction']],
                'states' => $regions,
            ],
        ],
    ], behavior: [
        'actions' => ['noteAction' => static function (): void {}],
    ]);

    // Ten region paths in total, so a budget of eight cuts the second region short.
    $cut = (new PathEnumerator($definition, 8))->enumerate();

    expect($cut->paths)->toBe([], 'the parent must record nothing, or its own gate would raise the flag')
        ->and($cut->pathLimitReached)->toBeTrue()
        ->and($cut->analysisTruncated())->toBeTrue();

    // And with room for all ten it reports itself complete, so the flag above is not
    // simply always on for this shape.
    $whole = (new PathEnumerator($definition, 10))->enumerate();

    expect($whole->paths)->toBe([])
        ->and($whole->pathLimitReached)->toBeFalse()
        ->and($whole->analysisTruncated())->toBeFalse();
});
