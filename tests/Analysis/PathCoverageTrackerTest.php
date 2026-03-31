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
        ->and($paths[0]['signature'])->toBe('idle→done');
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
        ->and($paths[0]['signature'])->toBe('idle→done')
        ->and($paths[1]['signature'])->toBe('idle→failed');
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
        ->and($paths[0]['signature'])->toBe('idle→done');

    unlink($tmpFile);
});

test('exportToDirectory writes PID-suffixed file', function (): void {
    PathCoverageTracker::enable();
    PathCoverageTracker::recordTransition('App\\Machine', 'machine.idle', null);
    PathCoverageTracker::completePath('App\\Machine');

    $tmpDir = sys_get_temp_dir().'/pca_test_dir_'.getmypid();
    PathCoverageTracker::setExportDirectory($tmpDir);
    PathCoverageTracker::exportToDirectory();

    $pid  = getmypid();
    $file = $tmpDir."/coverage_{$pid}.json";

    expect(file_exists($file))->toBeTrue();

    $data = json_decode(file_get_contents($file), true);
    expect($data)->toHaveKey('App\\Machine');

    // Cleanup
    unlink($file);
    rmdir($tmpDir);
});

test('importFromDirectory merges multiple worker files', function (): void {
    $tmpDir = sys_get_temp_dir().'/pca_merge_test_'.getmypid();
    mkdir($tmpDir, 0755, true);

    // Simulate worker 1 output
    file_put_contents($tmpDir.'/coverage_1001.json', json_encode([
        'App\\MachineA' => [
            ['signature' => 'idle→done', 'test' => 'test_a', 'steps' => []],
        ],
    ]));

    // Simulate worker 2 output
    file_put_contents($tmpDir.'/coverage_1002.json', json_encode([
        'App\\MachineA' => [
            ['signature' => 'idle→failed', 'test' => 'test_b', 'steps' => []],
        ],
        'App\\MachineB' => [
            ['signature' => 'start→end', 'test' => 'test_c', 'steps' => []],
        ],
    ]));

    PathCoverageTracker::importFromDirectory($tmpDir);

    // MachineA should have paths from both workers
    $pathsA = PathCoverageTracker::observedPaths('App\\MachineA');
    expect($pathsA)->toHaveCount(2)
        ->and($pathsA[0]['signature'])->toBe('idle→done')
        ->and($pathsA[1]['signature'])->toBe('idle→failed');

    // MachineB should have paths from worker 2
    $pathsB = PathCoverageTracker::observedPaths('App\\MachineB');
    expect($pathsB)->toHaveCount(1);

    // Cleanup
    unlink($tmpDir.'/coverage_1001.json');
    unlink($tmpDir.'/coverage_1002.json');
    rmdir($tmpDir);
});

test('cleanExportDirectory removes stale files', function (): void {
    $tmpDir = sys_get_temp_dir().'/pca_clean_test_'.getmypid();
    mkdir($tmpDir, 0755, true);

    // Create stale files
    file_put_contents($tmpDir.'/coverage_9999.json', '{}');
    file_put_contents($tmpDir.'/coverage_8888.json', '{}');

    PathCoverageTracker::setExportDirectory($tmpDir);
    PathCoverageTracker::cleanExportDirectory();

    $remaining = glob($tmpDir.'/coverage_*.json');
    expect($remaining)->toBe([]);

    // Cleanup
    rmdir($tmpDir);
});
