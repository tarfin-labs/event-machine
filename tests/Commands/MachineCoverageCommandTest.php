<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;
use Tarfinlabs\EventMachine\Analysis\PathCoverageTracker;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ReentrantParallelMachine;

/**
 * Write a coverage file the command can read, observing nothing.
 *
 * The command needs a coverage source to run at all; what these tests exercise is
 * the truncation reporting around the figure, not the figure itself.
 */
function emptyCoverageFile(): string
{
    $path = sys_get_temp_dir().'/em-coverage-'.bin2hex(random_bytes(6)).'.json';
    file_put_contents($path, json_encode([]));

    return $path;
}

/**
 * Run the command once and report both halves of the result.
 *
 * The exit code and the console output are asserted by different tests, but the
 * setup around them — a throwaway coverage file, and resetting the tracker so one
 * test's observations cannot leak into the next — is the same either way.
 *
 * @param  array<string, mixed>  $options
 *
 * @return array{code: int, output: string}
 */
function invokeCoverage(string $machine, array $options): array
{
    $from = emptyCoverageFile();

    try {
        $code = Artisan::call('machine:coverage', ['machine' => $machine, '--from' => $from] + $options);

        return ['code' => $code, 'output' => Artisan::output()];
    } finally {
        @unlink($from);
        PathCoverageTracker::reset();
    }
}

/**
 * @param  array<string, mixed>  $options
 */
function runCoverage(string $machine, array $options = []): string
{
    return invokeCoverage($machine, $options)['output'];
}

/**
 * @param  array<string, mixed>  $options
 */
function coverageExitCode(string $machine, array $options = []): int
{
    return invokeCoverage($machine, $options)['code'];
}

// ── Disclosure ───────────────────────────────────────────────────────────────

test('a complete enumeration carries no truncation notice', function (): void {
    $output = runCoverage(ReentrantParallelMachine::class);

    expect($output)->toContain('Coverage:')
        ->and($output)->not->toContain('Analysis incomplete');
});

test('a truncated enumeration is disclosed beside the figure', function (): void {
    $output = runCoverage(ReentrantParallelMachine::class, ['--max-depth' => 3]);

    expect($output)->toContain('Analysis incomplete')
        ->and($output)->toContain('excluded');
});

test('json carries the truncation flag and the excluded count', function (): void {
    $json = json_decode(runCoverage(ReentrantParallelMachine::class, ['--json' => true, '--max-depth' => 3]), true);

    expect($json)->toBeArray()
        ->and($json['analysis_truncated'])->toBeTrue()
        ->and($json['skipped_paths'])->toBeGreaterThan(0);
});

test('json reports a complete enumeration as complete', function (): void {
    $json = json_decode(runCoverage(ReentrantParallelMachine::class, ['--json' => true]), true);

    expect($json['analysis_truncated'])->toBeFalse()
        ->and($json['skipped_paths'])->toBe(0);
});

// ── The gate ─────────────────────────────────────────────────────────────────

test('a minimum cannot be enforced over a truncated analysis', function (): void {
    // The figure is computed over part of the machine, so clearing a threshold with it
    // would be a green gate over an unknown. Printing a warning is not enough: CI reads
    // the exit code.
    $output = runCoverage(ReentrantParallelMachine::class, ['--min' => 0, '--max-depth' => 3]);

    expect($output)->toContain('Cannot enforce a minimum over a truncated analysis')
        ->and($output)->toContain('--max-depth');

    expect(coverageExitCode(ReentrantParallelMachine::class, ['--min' => 0, '--max-depth' => 3]))
        ->toBe(Command::FAILURE);
});

test('a non-numeric minimum is rejected rather than silently ignored', function (): void {
    // (float) turns --min=abc and --min= into 0.0, which every run clears — a gate
    // disabled by a typo is worse than no gate, because it still looks like one.
    foreach (['abc', ''] as $bad) {
        $output = runCoverage(ReentrantParallelMachine::class, ['--min' => $bad]);

        expect($output)->toContain('--min must be a number')
            ->and($output)->not->toContain('below minimum');

        expect(coverageExitCode(ReentrantParallelMachine::class, ['--min' => $bad]))
            ->toBe(Command::FAILURE);
    }
});

test('a numeric minimum still reaches the comparison', function (): void {
    // The guard must reject only what it is for: a valid threshold is unaffected.
    $output = runCoverage(ReentrantParallelMachine::class, ['--min' => '75.5']);

    expect($output)->toContain('below minimum')
        ->and($output)->not->toContain('--min must be a number');
});

test('a minimum is enforced normally over a complete analysis', function (): void {
    // Nothing was observed, so coverage is 0% and a minimum of 1 must fail on the
    // figure itself rather than on truncation.
    $output = runCoverage(ReentrantParallelMachine::class, ['--min' => 1]);

    expect($output)->toContain('below minimum')
        ->and($output)->not->toContain('Cannot enforce a minimum');
});

test('the ceilings are configurable, so a truncated gate can be fixed', function (): void {
    // Without these options the warning names a problem the caller cannot act on.
    $output = runCoverage(ReentrantParallelMachine::class, ['--min' => 0, '--max-depth' => 200, '--max-paths' => 1000]);

    expect($output)->not->toContain('Cannot enforce a minimum over a truncated analysis');

    expect(coverageExitCode(ReentrantParallelMachine::class, ['--min' => 0, '--max-depth' => 200]))
        ->toBe(Command::SUCCESS);
});
