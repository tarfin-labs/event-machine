<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Analysis;

/**
 * Static singleton that accumulates state+event sequences during test execution.
 *
 * Follows the InlineBehaviorFake pattern: static state, enable/disable/reset.
 * Records transitions as (stateId, eventType) pairs, builds signatures
 * on completePath(), and tracks which test covered each path.
 */
class PathCoverageTracker
{
    private static bool $enabled = false;

    /** @var array<class-string, list<array{state: string, event: ?string}>> Active (in-progress) path per machine class. */
    private static array $activePaths = [];

    /** @var array<class-string, list<array{signature: string, test: string, steps: list<array{state: string, event: ?string}>}>> Completed paths. */
    private static array $observedPaths = [];

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

        /** @var array<class-string, list<array{signature: string, test: string, steps: list}>> $data */
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        self::$observedPaths = $data;
    }

    /**
     * Build a signature from recorded steps, matching MachinePath::signature() format.
     *
     * @param  list<array{state: string, event: ?string}>  $steps
     */
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
