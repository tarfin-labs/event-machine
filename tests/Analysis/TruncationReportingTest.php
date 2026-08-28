<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Analysis\MachineGraph;
use Tarfinlabs\EventMachine\Analysis\PathEnumerator;
use Tarfinlabs\EventMachine\Analysis\PathCoverageReport;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Analysis\ScenarioPathResolver;
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
    // so a region cut short still reported a complete analysis. Delete the propagation
    // in handleParallel and this is the test that notices.
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

    $regionTypes = array_map(static fn ($path): string => $path->type->value, $regionPaths);

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
