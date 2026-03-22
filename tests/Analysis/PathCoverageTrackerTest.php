<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Analysis\PathCoverageTracker;

beforeEach(function (): void {
    PathCoverageTracker::reset();
});

test('tracker records transitions and builds signature on completePath', function (): void {
    PathCoverageTracker::enable();

    PathCoverageTracker::recordTransition('App\\Machine', 'machine.idle', null);
    PathCoverageTracker::recordTransition('App\\Machine', 'machine.done', '@always');
    PathCoverageTracker::completePath('App\\Machine');

    $paths = PathCoverageTracker::observedPaths('App\\Machine');
    expect($paths)->toHaveCount(1)
        ->and($paths[0]['signature'])->toBe('idle→[@always]→done');
});

test('tracker does not record when disabled', function (): void {
    // Not enabled
    PathCoverageTracker::recordTransition('App\\Machine', 'machine.idle', null);
    PathCoverageTracker::completePath('App\\Machine');

    expect(PathCoverageTracker::observedPaths('App\\Machine'))->toBe([]);
});

test('completePath resets active path for new recording', function (): void {
    PathCoverageTracker::enable();

    // First path
    PathCoverageTracker::recordTransition('App\\Machine', 'machine.idle', null);
    PathCoverageTracker::recordTransition('App\\Machine', 'machine.done', 'GO');
    PathCoverageTracker::completePath('App\\Machine');

    // Second path
    PathCoverageTracker::recordTransition('App\\Machine', 'machine.idle', null);
    PathCoverageTracker::recordTransition('App\\Machine', 'machine.failed', '@fail');
    PathCoverageTracker::completePath('App\\Machine');

    $paths = PathCoverageTracker::observedPaths('App\\Machine');
    expect($paths)->toHaveCount(2)
        ->and($paths[0]['signature'])->toBe('idle→[GO]→done')
        ->and($paths[1]['signature'])->toBe('idle→[@fail]→failed');
});

test('reset clears all state', function (): void {
    PathCoverageTracker::enable();
    PathCoverageTracker::recordTransition('App\\Machine', 'machine.idle', null);
    PathCoverageTracker::completePath('App\\Machine');

    PathCoverageTracker::reset();

    expect(PathCoverageTracker::isEnabled())->toBeFalse()
        ->and(PathCoverageTracker::observedPaths('App\\Machine'))->toBe([]);
});

test('export and import roundtrip preserves data', function (): void {
    PathCoverageTracker::enable();
    PathCoverageTracker::recordTransition('App\\Machine', 'machine.idle', null);
    PathCoverageTracker::recordTransition('App\\Machine', 'machine.done', '@always');
    PathCoverageTracker::completePath('App\\Machine');

    $tmpFile = tempnam(sys_get_temp_dir(), 'pca_test_');
    PathCoverageTracker::exportToFile($tmpFile);

    PathCoverageTracker::reset();
    expect(PathCoverageTracker::observedPaths('App\\Machine'))->toBe([]);

    PathCoverageTracker::importFromFile($tmpFile);

    $paths = PathCoverageTracker::observedPaths('App\\Machine');
    expect($paths)->toHaveCount(1)
        ->and($paths[0]['signature'])->toBe('idle→[@always]→done');

    unlink($tmpFile);
});
