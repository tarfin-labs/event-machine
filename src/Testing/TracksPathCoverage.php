<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Testing;

use Tarfinlabs\EventMachine\Analysis\PathCoverageTracker;

/**
 * Trait for automatic path coverage tracking in test suites.
 *
 * Add `use TracksPathCoverage` to your base TestCase class.
 * Works with both PHPUnit and Pest, including parallel test runners (Paratest).
 *
 * Uses Laravel's setUp{TraitName} convention — automatically called before each test.
 * A static boot flag ensures enable + shutdown registration happen exactly once per process.
 *
 * Parallel-safe: each worker process writes a PID-suffixed coverage file.
 * The machine:coverage command merges all files when reporting.
 *
 * Usage:
 *   use Tests\TestCase;
 *   use Tarfinlabs\EventMachine\Testing\TracksPathCoverage;
 *
 *   // In tests/Pest.php:
 *   uses(TracksPathCoverage::class)->in('Feature', 'Unit');
 *
 *   // Or in a PHPUnit TestCase:
 *   abstract class TestCase extends BaseTestCase
 *   {
 *       use TracksPathCoverage;
 *   }
 */
trait TracksPathCoverage
{
    private static bool $pathCoverageBooted = false;

    protected function setUpTracksPathCoverage(): void
    {
        if (self::$pathCoverageBooted) {
            return;
        }

        self::$pathCoverageBooted = true;

        // Clean stale files from previous test runs (only first boot in this process)
        PathCoverageTracker::cleanExportDirectory();

        // Enable tracking
        PathCoverageTracker::enable();

        // Register shutdown function to export coverage when the process exits.
        // PID guard prevents double-export in forked child processes.
        $pid = getmypid();

        register_shutdown_function(static function () use ($pid): void {
            if ($pid !== getmypid()) {
                return;
            }

            if (PathCoverageTracker::isEnabled()) {
                PathCoverageTracker::exportToDirectory();
            }
        });
    }
}
