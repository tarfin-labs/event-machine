<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use Tarfinlabs\EventMachine\Analysis\MachineGraph;
use Tarfinlabs\EventMachine\Analysis\PathEnumerator;
use Tarfinlabs\EventMachine\Analysis\PathCoverageReport;
use Tarfinlabs\EventMachine\Analysis\PathCoverageTracker;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Analysis\ScenarioPathResolver;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\E2EBasicMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ReentrantParallelMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;

// ── The report tells the two ceilings apart ──────────────────────────────────

test('the report distinguishes a depth ceiling from a path ceiling', function (): void {
    $definition = ReentrantParallelMachine::definition();

    $complete = new PathCoverageReport((new PathEnumerator($definition))->enumerate(), []);
    $depth    = new PathCoverageReport((new PathEnumerator($definition, 1000, null, 3))->enumerate(), []);
    $paths    = new PathCoverageReport((new PathEnumerator($definition, 1))->enumerate(), []);

    expect($complete->enumerationTruncated())->toBeFalse()
        ->and($complete->depthTruncated())->toBeFalse();

    expect($depth->enumerationTruncated())->toBeTrue()
        ->and($depth->depthTruncated())->toBeTrue();

    // A path-limited run is truncated, but not by the depth ceiling — the assertions
    // below treat the two differently, so the report has to keep them apart.
    expect($paths->enumerationTruncated())->toBeTrue()
        ->and($paths->depthTruncated())->toBeFalse();
});

// ── The in-suite assertions ──────────────────────────────────────────────────

test('coverage assertions refuse a verdict over either ceiling', function (): void {
    // An earlier revision failed only on the depth ceiling, because the caller had no
    // way to raise the path ceiling through these assertions. Both are parameters now,
    // so the honest rule applies to both.
    foreach ([['maxPaths' => 1, 'ceiling' => 'path ceiling'], ['maxDepth' => 2, 'ceiling' => 'depth ceiling']] as $case) {
        $call = isset($case['maxPaths'])
            ? fn () => ReentrantParallelMachine::assertPathCoverage(0.0, maxPaths: 1)
            : fn () => ReentrantParallelMachine::assertPathCoverage(0.0, maxDepth: 2);

        try {
            $call();
            expect(false)->toBeTrue("expected a refusal for the {$case['ceiling']}");
        } catch (AssertionFailedError $e) {
            expect($e->getMessage())->toContain('stopped early')
                ->and($e->getMessage())->toContain($case['ceiling'])
                ->and($e->getMessage())->toContain('maxPaths/maxDepth');
        }
    }
});

test('raising the ceiling lets the same assertion proceed', function (): void {
    // The lever is what makes refusing defensible: a failure nobody can fix would be
    // worse than the gap it closes.
    expect(fn () => ReentrantParallelMachine::assertPathCoverage(0.0, maxPaths: 1))
        ->toThrow(AssertionFailedError::class);

    expect(fn () => ReentrantParallelMachine::assertPathCoverage(0.0, maxPaths: 1000, maxDepth: 200))
        ->not->toThrow(AssertionFailedError::class);
});

test('assertAllPathsCovered refuses a truncated analysis too', function (): void {
    expect(fn () => ReentrantParallelMachine::assertAllPathsCovered(maxDepth: 2))
        ->toThrow(AssertionFailedError::class);
});

test('coverage assertions accept a complete analysis', function (): void {
    // A machine deep enough that the default ceiling of 200 is not reached, then the
    // same machine enumerated through the assertion path with the ceiling in force is
    // not expressible — so this drives the guard directly, which is what it protects.
    $definition = MachineDefinition::define(config: [
        'id'      => 'depth_gate_probe',
        'initial' => 'a',
        'states'  => [
            'a' => ['on' => ['N' => 'b']],
            'b' => ['on' => ['N' => 'c']],
            'c' => ['type' => 'final'],
        ],
    ]);

    $truncated = new PathCoverageReport((new PathEnumerator($definition, 1000, null, 2))->enumerate(), []);

    expect($truncated->depthTruncated())->toBeTrue();

    // Machine::assertAnalysisWasComplete is private, so the behaviour is exercised
    // through the public assertion on a machine whose analysis is complete: it must NOT
    // trip the guard, which is the half that would break every existing suite if the
    // guard were wrong.
    expect(fn () => ReentrantParallelMachine::assertPathCoverage(minimum: 0.0))
        ->not->toThrow(AssertionFailedError::class);
});

test('a complete analysis lets the coverage assertions run normally', function (): void {
    // Nothing was observed, so coverage is 0%: a minimum above it must fail on the
    // figure, not on truncation, and the message must not mention truncation.
    try {
        ReentrantParallelMachine::assertPathCoverage(minimum: 50.0);
        expect(false)->toBeTrue('expected the assertion to fail on the coverage figure');
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())->toContain('below minimum')
            ->and($e->getMessage())->not->toContain('depth ceiling');
    }
});

test('an unmatched observation does not fail the coverage assertions', function (): void {
    // This pins a REVERT. An earlier revision failed assertAnalysisWasComplete() on an
    // unmatched observation — a run walked a route the analysis does not know about, which
    // reads like free evidence of an incomplete enumeration. It broke every consumer suite:
    // trackStateEntry records a region leaf id while enumeration records the parallel
    // container, so every parallel machine mismatches, and assertPathCoverage(0.0) —
    // documented as a threshold every run clears — became unsatisfiable.
    //
    // Nothing else catches a reinstatement: putting the gate back leaves the suite green.
    PathCoverageTracker::reset();
    PathCoverageTracker::enable();

    try {
        E2EBasicMachine::test()->assertFinished();

        $report = new PathCoverageReport(
            (new PathEnumerator(E2EBasicMachine::definition()))->enumerate(),
            PathCoverageTracker::observedPaths(E2EBasicMachine::class),
        );

        // The precondition: without a mismatch this test would pass for the wrong reason.
        expect($report->unmatchedObservations())->not->toBeEmpty();

        expect(fn () => E2EBasicMachine::assertPathCoverage(minimum: 0.0))
            ->not->toThrow(AssertionFailedError::class);
    } finally {
        PathCoverageTracker::reset();
    }
});

// ── Resolver and enumerator agree about a parallel state's transitions ───────

test('the resolver follows an inherited transition on a parallel state', function (): void {
    // The resolver read $state->transitionDefinitions (own only) while the enumerator
    // uses transitionsFrom() (own plus ancestors). Left as it was, the analyser
    // disagreement this work set out to remove would merely have been inverted.
    $definition = MachineDefinition::define(config: [
        'id'      => 'inherited_on_parallel',
        'initial' => 'idle',
        'states'  => [
            'idle'    => ['on' => ['START' => 'working']],
            'working' => [
                'type'   => 'parallel',
                '@done'  => 'finished',
                'states' => [
                    'x' => ['initial' => 'x1', 'states' => ['x1' => ['on' => ['XD' => 'x2']], 'x2' => ['type' => 'final']]],
                ],
            ],
            'finished' => ['type' => 'final'],
            'bailed'   => ['type' => 'final'],
        ],
    ]);

    $resolver = new ScenarioPathResolver(new MachineGraph($definition));

    // Reaching a region-interior target requires the region descent.
    expect($resolver->resolveAll('idle', 'START', 'working.x.x1'))->not->toBeEmpty();

    // And the parallel state's own @done is still followed.
    expect($resolver->resolveAll('idle', 'START', 'finished'))->not->toBeEmpty();
});

test('the resolver marks region entry distinctly from compound entry', function (): void {
    $resolver = new ScenarioPathResolver(new MachineGraph(ScenarioTestMachine::definition()));

    $paths = $resolver->resolveAll('reviewing', 'START_PARALLEL', 'parallel_check.region_a.checking_a');

    expect($paths)->toHaveCount(1);

    $events = array_map(static fn ($step): ?string => $step->event, $paths[0]->steps);

    // @region, not @entry: entering a parallel state activates every region at once, so
    // this route is a projection of a concurrent configuration rather than an exclusive
    // descent into one child.
    expect($events)->toContain('@region')
        ->and($events)->not->toContain('@entry');
});
