<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Analysis\PathStep;
use Tarfinlabs\EventMachine\Analysis\PathType;
use Tarfinlabs\EventMachine\Analysis\MachinePath;
use Tarfinlabs\EventMachine\Analysis\PathCoverageReport;
use Tarfinlabs\EventMachine\Analysis\PathEnumerationResult;

function makeTestPath(string $key, PathType $type = PathType::HAPPY): MachinePath
{
    return new MachinePath(
        steps: [
            new PathStep(stateId: 'm.idle', stateKey: 'idle'),
            new PathStep(stateId: "m.{$key}", stateKey: $key, event: 'GO'),
        ],
        type: $type,
        terminalStateId: "m.{$key}",
    );
}

test('coverage report correctly partitions covered and uncovered paths', function (): void {
    $path1 = makeTestPath('done');
    $path2 = makeTestPath('failed', PathType::FAIL);
    $path3 = makeTestPath('expired', PathType::TIMEOUT);

    $result = new PathEnumerationResult(paths: [$path1, $path2, $path3]);

    $observed = [
        ['signature' => $path1->stateSignature(), 'test' => 'test_happy_path'],
        ['signature' => $path2->stateSignature(), 'test' => 'test_fail_path'],
    ];

    $report = new PathCoverageReport($result, $observed);

    expect($report->coveredPaths())->toHaveCount(2)
        ->and($report->uncoveredPaths())->toHaveCount(1)
        ->and($report->coveragePercentage())->toBe(66.7);
});

test('testedBy returns test names for a covered path', function (): void {
    $path = makeTestPath('done');

    $result = new PathEnumerationResult(paths: [$path]);

    $observed = [
        ['signature' => $path->stateSignature(), 'test' => 'test_a'],
        ['signature' => $path->stateSignature(), 'test' => 'test_b'],
    ];

    $report = new PathCoverageReport($result, $observed);

    expect($report->testedBy($path))->toBe(['test_a', 'test_b']);
});

test('100% coverage when all paths are observed', function (): void {
    $path = makeTestPath('done');

    $result   = new PathEnumerationResult(paths: [$path]);
    $observed = [['signature' => $path->stateSignature(), 'test' => 'test_x']];

    $report = new PathCoverageReport($result, $observed);

    expect($report->coveragePercentage())->toBe(100.0)
        ->and($report->uncoveredPaths())->toBe([]);
});

test('empty enumeration returns 100% coverage', function (): void {
    $result = new PathEnumerationResult();
    $report = new PathCoverageReport($result, []);

    expect($report->coveragePercentage())->toBe(100.0);
});

test('an observed path matching nothing enumerated is reported, not discarded', function (): void {
    // computeCoverage walked only the enumerated side, so a signature a run actually took
    // with no enumerated counterpart fell out of the arithmetic entirely. The report then
    // answered 100% while holding the proof it was incomplete: every enumerated path is
    // covered, and the route nobody enumerated is invisible.
    $enumerated = makeTestPath('done');

    $report = new PathCoverageReport(
        enumeration: new PathEnumerationResult(paths: [$enumerated]),
        observedPaths: [
            ['signature' => $enumerated->stateSignature(), 'test' => 'covers the known path'],
            ['signature' => 'm.idle → m.somewhere_else', 'test' => 'walked a route nobody enumerated'],
        ],
    );

    expect($report->coveragePercentage())->toBe(100.0)
        ->and($report->uncoveredPaths())->toBe([])
        ->and($report->unmatchedObservations())->toBe(['m.idle → m.somewhere_else']);
});

test('nothing is reported unmatched when every observation lines up', function (): void {
    $enumerated = makeTestPath('done');

    $report = new PathCoverageReport(
        enumeration: new PathEnumerationResult(paths: [$enumerated]),
        observedPaths: [
            ['signature' => $enumerated->stateSignature(), 'test' => 'one'],
            ['signature' => $enumerated->stateSignature(), 'test' => 'two'],
        ],
    );

    expect($report->unmatchedObservations())->toBe([]);
});
