<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Illuminate\Console\Command;
use Tarfinlabs\EventMachine\Analysis\PathEnumerator;
use Tarfinlabs\EventMachine\Analysis\PathCoverageReport;
use Tarfinlabs\EventMachine\Analysis\PathCoverageTracker;

class MachineCoverageCommand extends Command
{
    protected $signature = 'machine:coverage
        {machine : The Machine class path or FQCN}
        {--json : Output as JSON}
        {--min= : Minimum coverage percentage (exit code 1 if below)}
        {--from= : Path to coverage directory or JSON file}';
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

        // Enumerate paths and build report
        $definition  = $machinePath::definition();
        $enumerator  = new PathEnumerator($definition);
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
            $coverage = $report->coveragePercentage();

            if ($coverage < (float) $min) {
                $this->error(sprintf('Path coverage %.1f%% is below minimum %s%%', $coverage, $min));

                return self::FAILURE;
            }
        }

        PathCoverageTracker::reset();

        return self::SUCCESS;
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

                    $this->line($line.$step->stateKey);
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
            'machine'      => class_basename($machinePath),
            'total_paths'  => $total,
            'tested_paths' => count($covered),
            'coverage'     => $report->coveragePercentage(),
            'paths'        => $paths,
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

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
