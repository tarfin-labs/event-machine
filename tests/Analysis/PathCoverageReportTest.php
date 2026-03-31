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
