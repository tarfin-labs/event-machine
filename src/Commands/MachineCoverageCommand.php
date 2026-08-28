<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Illuminate\Console\Command;
use Tarfinlabs\EventMachine\Analysis\PathEnumerator;
use Tarfinlabs\EventMachine\Analysis\PathCoverageReport;
use Tarfinlabs\EventMachine\Analysis\PathCoverageTracker;

class MachineCoverageCommand extends Command
{
    use ValidatesNumericOptions;

    protected $signature = 'machine:coverage
        {machine : The Machine class path or FQCN}
        {--json : Output as JSON}
        {--min= : Minimum coverage percentage (exit code 1 if below)}
        {--from= : Path to coverage directory or JSON file}
        {--max-paths=1000 : Maximum paths to enumerate}
        {--max-depth=200 : Maximum total analysis depth before a path is cut short}';
    protected $description = 'Report path coverage for a machine definition';

    public function handle(): int
    {
        $machinePath = $this->argument('machine');

        // Resolve file path to FQCN
        if (str_ends_with($machinePath, '.php') || str_contains($machinePath, DIRECTORY_SEPARATOR)) {
            $machinePath = $this->resolveClassFromFile($machinePath);

            if ($machinePath === null) {
                $this->error('Could not resolve a Machine class from the given file path.');

                return self::FAILURE;
            }
        }

        if (!class_exists($machinePath)) {
            $this->error("Machine class not found: {$machinePath}");

            return self::FAILURE;
        }

        // Load coverage data — supports both directory (parallel workers) and single file
        $from = $this->option('from') ?? PathCoverageTracker::getExportDirectory();

        if (is_dir($from)) {
            // Directory mode: merge all coverage_*.json files from parallel workers
            $files = glob($from.'/coverage_*.json');

            if ($files === false || $files === []) {
                $this->error("No coverage files found in: {$from}");
                $this->line('Run your test suite with TracksPathCoverage trait first.');

                return self::FAILURE;
            }

            PathCoverageTracker::importFromDirectory($from);
        } elseif (file_exists($from)) {
            // Single file mode (legacy / manual export)
            PathCoverageTracker::importFromFile($from);
        } else {
            $this->error("Coverage path not found: {$from}");
            $this->line('Run your test suite with TracksPathCoverage trait first.');

            return self::FAILURE;
        }

        // Importing populates a process-wide static singleton. Resetting it only on the
        // success path left it populated after every failure return below, so one
        // invocation's observations could leak into the next within the same process.
        // The tests stayed isolated only because their helper reset it by hand — which is
        // the test compensating for something the command should guarantee.
        try {
            // Enumerate paths and build report
            $definition = $machinePath::definition();
            $maxPaths   = $this->integerOption('max-paths', min: 1);
            $maxDepth   = $this->integerOption('max-depth', min: 1);

            if ($maxPaths === null || $maxDepth === null) {
                return self::FAILURE;
            }

            $enumerator = new PathEnumerator(
                definition: $definition,
                maxPaths: $maxPaths,
                maxDepth: $maxDepth,
            );
            $enumeration = $enumerator->enumerate();
            $observed    = PathCoverageTracker::observedPaths($machinePath);
            $report      = new PathCoverageReport($enumeration, $observed);

            if ($this->option('json')) {
                $this->line($this->renderJson($report, $machinePath));
            } else {
                $this->renderConsole($report, $machinePath);
            }

            // Check minimum threshold
            $min = $this->option('min');

            if ($min !== null) {
                // A gate silently disabled by a typo is worse than no gate: (float) turns
                // --min=abc and --min= into 0.0, which every run clears.
                if (!is_numeric($min)) {
                    $this->error("--min must be a number between 0 and 100, got '{$min}'.");

                    return self::FAILURE;
                }

                // A threshold is an explicit gate, and a percentage computed over an
                // enumeration that stopped early cannot clear it honestly: the paths never
                // enumerated cannot be counted as uncovered, so the figure flatters itself.
                // Printing a warning is not enough here — CI reads the exit code.
                if ($report->enumerationTruncated()) {
                    $this->error('Cannot enforce a minimum over a truncated analysis: enumeration stopped early, so the coverage figure is computed over part of the machine.');
                    $this->line('Raise the ceiling with --max-paths or --max-depth, then re-run.');

                    return self::FAILURE;
                }

                $coverage = $report->coveragePercentage();

                if ($coverage < (float) $min) {
                    $this->error(sprintf('Path coverage %.1f%% is below minimum %s%%', $coverage, $min));

                    return self::FAILURE;
                }
            }

            return self::SUCCESS;
        } finally {
            // reset() also clears the enabled flag, and TracksPathCoverage enables the
            // tracker once per process and exports on shutdown. Without restoring it, an
            // in-process run under that trait would stop collection for the rest of the
            // suite and skip the export entirely.
            //
            // This does not make an in-process run harmless. importFromFile and
            // importFromDirectory REPLACE $observedPaths, so whatever the suite had
            // recorded is already gone by the time this block runs; restoring the flag
            // keeps collection alive from here on but cannot bring those back. Running
            // machine:coverage inside a suite that is itself tracking coverage is still
            // the wrong shape — the documented flow is a separate process.
            $wasEnabled = PathCoverageTracker::isEnabled();

            PathCoverageTracker::reset();

            if ($wasEnabled) {
                PathCoverageTracker::enable();
            }
        }
    }

    private function renderConsole(PathCoverageReport $report, string $machinePath): void
    {
        $name       = class_basename($machinePath);
        $covered    = $report->coveredPaths();
        $uncovered  = $report->uncoveredPaths();
        $total      = count($covered) + count($uncovered);
        $percentage = $report->coveragePercentage();

        $this->line('');
        $this->line("{$name} — Path Coverage");
        $this->line(str_repeat('═', mb_strlen("{$name} — Path Coverage")));
        $this->line('');
        $this->line(sprintf('  Coverage: %d/%d paths (%.1f%%)', count($covered), $total, $percentage));

        // A percentage computed over an enumeration that stopped early is not a
        // coverage guarantee, and without this line it looks exactly like one.
        if ($report->enumerationTruncated()) {
            $this->warn(sprintf(
                '  Analysis incomplete: enumeration stopped early, %d path(s) excluded. This figure covers only what was enumerated.',
                $report->skippedPathCount(),
            ));
        }

        // A signature a test actually walked, with nothing enumerated to match it, says
        // the analysis missed a route the machine can take. Without this the report can
        // read 100% while holding the evidence against itself.
        $unmatched = $report->unmatchedObservations();

        if ($unmatched !== []) {
            $this->warn(sprintf(
                '  %d observed path(s) match nothing enumerated — the analysis is missing routes the machine took, or this coverage data predates the current definition.',
                count($unmatched),
            ));

            foreach (array_slice($unmatched, 0, 3) as $signature) {
                $this->line("    {$signature}");
            }

            if (count($unmatched) > 3) {
                $this->line('    … and '.(count($unmatched) - 3).' more');
            }
        }
        $this->line('');

        $pathNumber = 1;

        // Covered paths: one-line summary with "Tested by"
        foreach ($covered as $entry) {
            $path = $entry['path'];
            $this->line("  ✓ #{$pathNumber}  {$path->signature()}");

            if ($entry['tests'] !== []) {
                $this->line('         Tested by: '.implode(', ', $entry['tests']));
            }

            $pathNumber++;
        }

        // Uncovered paths: one-line marker
        foreach ($uncovered as $path) {
            $this->line("  ✗ #{$pathNumber}  {$path->signature()}");
            $pathNumber++;
        }

        // Untested detail section
        if ($uncovered !== []) {
            $this->line('');
            $this->line('UNTESTED: '.count($uncovered).' path'.(count($uncovered) !== 1 ? 's' : ''));

            foreach ($uncovered as $path) {
                foreach ($path->steps as $step) {
                    $line = '  → ';

                    if ($step->event !== null) {
                        $line = "  → [{$step->event}] ";
                    }

                    $display = $step->stateKey;

                    if ($step->invokeClass !== null) {
                        $display .= ' ('.class_basename($step->invokeClass).')';
                    }

                    $this->line($line.$display);
                }

                $this->line('');
            }
        }
    }

    private function renderJson(PathCoverageReport $report, string $machinePath): string
    {
        $covered   = $report->coveredPaths();
        $uncovered = $report->uncoveredPaths();
        $total     = count($covered) + count($uncovered);

        $paths = [];

        $id = 1;

        foreach ($covered as $entry) {
            $paths[] = [
                'id'        => $id++,
                'type'      => $entry['path']->type->value,
                'signature' => $entry['path']->signature(),
                'tested'    => true,
                'tests'     => $entry['tests'],
            ];
        }

        foreach ($uncovered as $path) {
            $paths[] = [
                'id'        => $id++,
                'type'      => $path->type->value,
                'signature' => $path->signature(),
                'tested'    => false,
                'tests'     => [],
            ];
        }

        $data = [
            'machine'            => class_basename($machinePath),
            'total_paths'        => $total,
            'tested_paths'       => count($covered),
            'coverage'           => $report->coveragePercentage(),
            'analysis_truncated' => $report->enumerationTruncated(),
            'skipped_paths'      => $report->skippedPathCount(),
            'unmatched_observed' => $report->unmatchedObservations(),
            'paths'              => $paths,
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Resolve a FQCN from a PHP file path by extracting namespace and class name.
     *
     * One of three byte-identical copies (see MachinePathsCommand and ExportXStateCommand).
     * It `require_once`s the file rather than only reading it, and its regex takes the
     * FIRST extending class in the file — so a file that declares a helper class above the
     * machine resolves to the wrong FQCN.
     */
    private function resolveClassFromFile(string $filePath): ?string
    {
        if (!file_exists($filePath)) {
            $filePath = base_path($filePath);
            if (!file_exists($filePath)) {
                return null;
            }
        }

        $contents  = file_get_contents($filePath);
        $namespace = null;
        $class     = null;

        if (preg_match('/namespace\s+([^;]+);/', $contents, $matches)) {
            $namespace = trim($matches[1]);
        }

        if (preg_match('/class\s+(\w+)\s+extends/', $contents, $matches)) {
            $class = $matches[1];
        }

        if ($class === null) {
            return null;
        }

        $fqcn = $namespace !== null ? $namespace.'\\'.$class : $class;

        if (!class_exists($fqcn)) {
            require_once $filePath;
        }

        return class_exists($fqcn) ? $fqcn : null;
    }
}
