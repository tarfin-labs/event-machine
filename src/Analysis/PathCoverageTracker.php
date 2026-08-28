<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Analysis;

/**
 * Static singleton that accumulates state+event sequences during test execution.
 *
 * Follows the InlineBehaviorFake pattern: static state, enable/disable/reset.
 * Records transitions as (stateId, eventType) pairs, builds signatures
 * on completePath(), and tracks which test covered each path.
 *
 * Parallel-safe: each worker writes a PID-suffixed file. The machine:coverage
 * command merges all partial files when reading.
 */
class PathCoverageTracker
{
    private static bool $enabled = false;

    /** @var array<class-string, list<array{state: string, event: ?string}>> Active (in-progress) path per machine class. */
    private static array $activePaths = [];

    /** @var array<class-string, list<array{signature: string, test: string, steps: list<array{state: string, event: ?string}>}>> Completed paths. */
    private static array $observedPaths = [];

    /** Default export directory (relative to getcwd()). */
    private const string DEFAULT_EXPORT_DIRECTORY = 'storage/machine-path-coverage';

    private static string $exportDirectory = self::DEFAULT_EXPORT_DIRECTORY;

    /**
     * Whether this process has already booted tracking (enable + shutdown export).
     *
     * It lives here rather than on TracksPathCoverage because a trait's statics are copied
     * into every using class: a project with two base TestCases both using the trait booted
     * twice, and the second boot's cleanExportDirectory() deleted coverage files that finished
     * workers had already written. "Once per process" has to mean once per process.
     */
    private static bool $booted = false;

    public static function enable(): void
    {
        self::$enabled = true;
    }

    public static function disable(): void
    {
        self::$enabled = false;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    public static function reset(): void
    {
        self::$enabled       = false;
        self::$activePaths   = [];
        self::$observedPaths = [];
        // The export directory is process state like the rest of it. Leaving it set meant a
        // test that pointed the tracker at a temp dir and deleted the dir left every later
        // --from-less `machine:coverage` in that process reading a path that no longer exists.
        self::$exportDirectory = self::DEFAULT_EXPORT_DIRECTORY;
        self::$booted          = false;
    }

    /**
     * Claim the once-per-process boot, returning false if it was already claimed.
     */
    public static function claimBoot(): bool
    {
        if (self::$booted) {
            return false;
        }

        self::$booted = true;

        return true;
    }

    /**
     * Drop half-walked paths without touching what has already been observed.
     *
     * A path is only moved out of the active buffer by completePath(), which fires when a
     * machine reaches a final state. A test that drives a machine partway and stops — the
     * common shape for asserting an intermediate state — leaves its steps behind, and the
     * next test's completion flushes them out as one signature: a route the machine never
     * took, recorded against the wrong test. Test harnesses call this between tests;
     * observed paths survive because they are the point of the run.
     */
    public static function discardActivePaths(): void
    {
        self::$activePaths = [];
    }

    /**
     * Set the export directory path.
     */
    public static function setExportDirectory(string $directory): void
    {
        self::$exportDirectory = $directory;
    }

    /**
     * Get the resolved export directory (absolute path).
     */
    public static function getExportDirectory(): string
    {
        $dir = self::$exportDirectory;

        // If relative, resolve against cwd
        if (!str_starts_with($dir, '/')) {
            return getcwd().'/'.$dir;
        }

        return $dir;
    }

    /**
     * Clean the export directory — removes stale files from previous test runs.
     * Should be called once at suite start (before any workers write).
     */
    public static function cleanExportDirectory(): void
    {
        $dir = self::getExportDirectory();

        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir.'/coverage_*.json');

        foreach ($files !== false ? $files : [] as $file) {
            unlink($file);
        }
    }

    /**
     * Append a (stateId, eventType) step to the active path for this machine class.
     */
    public static function recordTransition(string $machineClass, string $stateId, ?string $eventType = null): void
    {
        if (!self::$enabled) {
            return;
        }

        self::$activePaths[$machineClass][] = [
            'state' => $stateId,
            'event' => $eventType,
        ];
    }

    /**
     * Move the active path to observed paths, recording the current test name.
     */
    public static function completePath(string $machineClass): void
    {
        if (!self::$enabled) {
            return;
        }

        $steps = self::$activePaths[$machineClass] ?? [];

        if ($steps === []) {
            return;
        }

        $signature = self::buildSignature($steps);
        $testName  = self::resolveTestName();

        self::$observedPaths[$machineClass][] = [
            'signature' => $signature,
            'test'      => $testName,
            'steps'     => $steps,
        ];

        // Reset active path for this machine
        self::$activePaths[$machineClass] = [];
    }

    /**
     * Get all completed observed paths for a machine class.
     *
     * @return list<array{signature: string, test: string, steps: list<array{state: string, event: ?string}>}>
     */
    public static function observedPaths(string $machineClass): array
    {
        return self::$observedPaths[$machineClass] ?? [];
    }

    /**
     * Get all observed paths across all machine classes.
     *
     * @return array<class-string, list<array{signature: string, test: string}>>
     */
    public static function allObservedPaths(): array
    {
        return self::$observedPaths;
    }

    /**
     * Export all observed paths to a JSON file.
     */
    public static function exportToFile(string $path): void
    {
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, json_encode(self::$observedPaths, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * Export observed paths to the export directory with a PID-suffixed filename.
     * Parallel-safe: each worker writes its own file, no conflicts.
     */
    public static function exportToDirectory(): void
    {
        if (self::$observedPaths === []) {
            return;
        }

        $dir = self::getExportDirectory();

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $pid  = getmypid();
        $path = $dir."/coverage_{$pid}.json";

        file_put_contents($path, json_encode(self::$observedPaths, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * Import observed paths from a JSON file.
     */
    public static function importFromFile(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        $content = file_get_contents($path);

        if ($content === false) {
            return;
        }

        /** @var array<class-string, list<array{signature: string, test: string, steps: list<array{state: string, event: string|null}>}>> $data */
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        self::$observedPaths = $data;
    }

    /**
     * Import and merge all coverage files from the export directory.
     *
     * Each parallel worker writes a separate coverage_{pid}.json file.
     * This method reads all of them and merges the observed paths.
     */
    public static function importFromDirectory(?string $directory = null): void
    {
        $dir   = $directory ?? self::getExportDirectory();
        $files = glob($dir.'/coverage_*.json');

        if ($files === false || $files === []) {
            return;
        }

        self::$observedPaths = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);

            if ($content === false) {
                continue;
            }

            /** @var array<class-string, list<array{signature: string, test: string, steps: list<array{state: string, event: string|null}>}>> $data */
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            foreach ($data as $machineClass => $paths) {
                foreach ($paths as $path) {
                    self::$observedPaths[$machineClass][] = $path;
                }
            }
        }
    }

    /**
     * Build a state-only signature for coverage matching.
     *
     * Uses only state keys (not events) to match against MachinePath::stateSignature().
     * Event types from the runtime tracker are unreliable (internal events,
     * triggeringEvent preservation), so matching ignores them.
     *
     * @param  list<array{state: string, event: ?string}>  $steps
     */
    private static function buildSignature(array $steps): string
    {
        $parts = [];

        foreach ($steps as $step) {
            // Extract the state key from the full ID (last segment after delimiter)
            $stateKey = str_contains($step['state'], '.')
                ? substr($step['state'], strrpos($step['state'], '.') + 1)
                : $step['state'];

            $parts[] = $stateKey;
        }

        return implode('→', $parts);
    }

    /**
     * Resolve the current test name from the backtrace.
     */
    private static function resolveTestName(): string
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);

        foreach ($backtrace as $frame) {
            if (isset($frame['file']) && str_contains($frame['file'], '/tests/')) {
                $function = $frame['function'];
                // Pest test closures have the test description in the file
                if ($function === '{closure}') {
                    continue;
                }
                if ($function === '__pest_evaluable_') {
                    continue;
                }

                return $function;
            }
        }

        return 'unknown';
    }
}
