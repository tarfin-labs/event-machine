<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Testing\TracksPathCoverage;
use Tarfinlabs\EventMachine\Analysis\PathCoverageTracker;
use Tarfinlabs\EventMachine\Testing\InteractsWithMachines;

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

test('a half-walked path does not leak into the next completed one', function (): void {
    // completePath() is the only thing that empties the active buffer, and it fires when a
    // machine reaches a final state. A test that drives a machine partway and stops — the
    // ordinary shape for asserting an intermediate state — used to leave its steps behind
    // for the next completion to flush out as one signature, producing a route the machine
    // never took and attributing it to the wrong test.
    PathCoverageTracker::reset();
    PathCoverageTracker::enable();

    try {
        // Test A: drives partway and never reaches a final state.
        PathCoverageTracker::recordTransition('LeakProbeMachine', 'm.idle');
        PathCoverageTracker::recordTransition('LeakProbeMachine', 'm.processing', 'START');

        // What a harness does between tests.
        PathCoverageTracker::discardActivePaths();

        // Test B: a complete run of its own.
        PathCoverageTracker::recordTransition('LeakProbeMachine', 'm.idle');
        PathCoverageTracker::recordTransition('LeakProbeMachine', 'm.done', 'FINISH');
        PathCoverageTracker::completePath('LeakProbeMachine');

        $observed = PathCoverageTracker::observedPaths('LeakProbeMachine');

        expect($observed)->toHaveCount(1)
            ->and($observed[0]['signature'])->toBe('idle→done')
            ->and($observed[0]['signature'])->not->toContain('processing');
    } finally {
        PathCoverageTracker::reset();
    }
});

test('both test traits discard half-walked paths at set-up', function (): void {
    // The discard has to be wired into the trait a suite actually has. TracksPathCoverage is
    // the one that turns tracking on and the only one the documented adoption steps name, so
    // a suite following them exactly used to get the leak; InteractsWithMachines carries it
    // too because a suite may use only that one. Set-up, not teardown: trait teardowns run
    // LIFO under Testbench and FIFO under Laravel, so a teardown discard can land before
    // another trait's teardown calls completePath() and swallow it.
    $harnesses = [
        new class() {
            use TracksPathCoverage;

            public function boot(): void
            {
                // Skip the once-per-process boot: it cleans the export directory and
                // registers a shutdown export, neither of which belongs in a unit test.
                // The discard runs before that guard, which is the point.
                self::$pathCoverageBooted = true;

                $this->setUpTracksPathCoverage();
            }
        },
        new class() {
            use InteractsWithMachines;

            public function boot(): void
            {
                $this->setUpInteractsWithMachines();
            }
        },
    ];

    foreach ($harnesses as $harness) {
        PathCoverageTracker::reset();
        PathCoverageTracker::enable();

        try {
            PathCoverageTracker::recordTransition('HookProbeMachine', 'm.idle');
            PathCoverageTracker::recordTransition('HookProbeMachine', 'm.processing', 'START');

            $harness->boot();

            PathCoverageTracker::recordTransition('HookProbeMachine', 'm.idle');
            PathCoverageTracker::recordTransition('HookProbeMachine', 'm.done', 'FINISH');
            PathCoverageTracker::completePath('HookProbeMachine');

            $observed = PathCoverageTracker::observedPaths('HookProbeMachine');

            expect($observed)->toHaveCount(1)
                ->and($observed[0]['signature'])->toBe('idle→done');
        } finally {
            PathCoverageTracker::reset();
        }
    }
});

test('discarding active paths keeps what has already been observed', function (): void {
    // The buffer and the record are different things: a run's whole point is the observed
    // set, and it has to survive the between-test cleanup that drops half-walked paths.
    PathCoverageTracker::reset();
    PathCoverageTracker::enable();

    try {
        PathCoverageTracker::recordTransition('KeepProbeMachine', 'm.idle');
        PathCoverageTracker::recordTransition('KeepProbeMachine', 'm.done', 'GO');
        PathCoverageTracker::completePath('KeepProbeMachine');

        PathCoverageTracker::discardActivePaths();

        expect(PathCoverageTracker::observedPaths('KeepProbeMachine'))->toHaveCount(1);
    } finally {
        PathCoverageTracker::reset();
    }
});
