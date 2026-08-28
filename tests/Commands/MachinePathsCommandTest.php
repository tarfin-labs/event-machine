<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tarfinlabs\EventMachine\ContextManager;
use Symfony\Component\Console\Command\Command;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\RegionBoundaryMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ReentrantParallelMachine;

/**
 * Run machine:paths and return its console output.
 *
 * Artisan::call rather than $this->artisan(...): these tests assert on the shape of
 * the output, and reading it back is unambiguous where a chain of expectation helpers
 * is not.
 *
 * @param  array<string, mixed>  $options
 */
function runPaths(string $machine, array $options = []): string
{
    Artisan::call('machine:paths', ['machine' => $machine] + $options);

    return Artisan::output();
}

/**
 * @param  array<string, mixed>  $options
 *
 * @return array<string, mixed>
 */
function runPathsJson(string $machine, array $options = []): array
{
    $decoded = json_decode(runPaths($machine, $options + ['--json' => true]), true);

    expect($decoded)->toBeArray();

    return $decoded;
}

// ── Baseline: nothing is claimed that is not true ────────────────────────────

test('a complete analysis carries no truncation notice', function (): void {
    $output = runPaths(ReentrantParallelMachine::class);

    expect($output)->toContain('HAPPY PATHS')
        ->and($output)->toContain('LOOP PATHS')
        ->and($output)->not->toContain('Analysis incomplete')
        ->and($output)->not->toContain('limit reached')
        ->and($output)->not->toContain('TRUNCATED PATHS');
});

test('the parallel summary counts every parallel state once', function (): void {
    // This line printed once per group with a hardcoded 1, so a machine with two
    // parallel states said "Parallel regions: 1" twice.
    $output = runPaths(ReentrantParallelMachine::class);

    expect($output)->toContain('Parallel states: 2 (4 regions)')
        ->and(substr_count($output, 'Parallel states:'))->toBe(1)
        ->and($output)->toContain('data_collection: 2 regions')
        ->and($output)->toContain('verification: 2 regions');
});

// ── Truncation disclosure ────────────────────────────────────────────────────

test('the depth ceiling is disclosed on the console', function (): void {
    $output = runPaths(ReentrantParallelMachine::class, ['--max-depth' => 3]);

    expect($output)->toContain('Analysis incomplete')
        ->and($output)->toContain('depth limit reached')
        ->and($output)->toContain('--max-depth')
        ->and($output)->toContain('TRUNCATED PATHS');
});

test('the path ceiling is disclosed on the console', function (): void {
    $output = runPaths(ReentrantParallelMachine::class, ['--max-paths' => 1]);

    expect($output)->toContain('Analysis incomplete')
        ->and($output)->toContain('path limit reached')
        ->and($output)->toContain('--max-paths');
});

test('both ceilings are named when both fire', function (): void {
    $output = runPaths(ReentrantParallelMachine::class, ['--max-paths' => 1, '--max-depth' => 2]);

    expect($output)->toContain('path limit reached')
        ->and($output)->toContain('depth limit reached');
});

// ── JSON surface ─────────────────────────────────────────────────────────────

test('json reports a complete analysis as complete', function (): void {
    $stats = runPathsJson(ReentrantParallelMachine::class)['stats'];

    expect($stats['analysis_truncated'])->toBeFalse()
        ->and($stats['path_limit_reached'])->toBeFalse()
        ->and($stats['depth_limit_reached'])->toBeFalse()
        ->and($stats['truncated_paths'])->toBe(0)
        ->and($stats['terminal_paths'])->toBe(3);
});

test('json flags a depth-truncated analysis', function (): void {
    $stats = runPathsJson(ReentrantParallelMachine::class, ['--max-depth' => 3])['stats'];

    expect($stats['analysis_truncated'])->toBeTrue()
        ->and($stats['depth_limit_reached'])->toBeTrue()
        ->and($stats['path_limit_reached'])->toBeFalse()
        ->and($stats['truncated_paths'])->toBeGreaterThan(0);
});

test('json flags a path-truncated analysis', function (): void {
    $stats = runPathsJson(ReentrantParallelMachine::class, ['--max-paths' => 1])['stats'];

    expect($stats['analysis_truncated'])->toBeTrue()
        ->and($stats['path_limit_reached'])->toBeTrue();
});

test('terminal_paths keeps counting the whole paths array', function (): void {
    // The truncated count lives in its own key rather than redefining this one.
    $json = runPathsJson(ReentrantParallelMachine::class, ['--max-depth' => 3]);

    expect($json['stats']['terminal_paths'])->toBe(count($json['paths']));
});

test('json exposes region paths, which reached no consumer before', function (): void {
    $json = runPathsJson(ReentrantParallelMachine::class);

    expect($json['parallel_groups'])->toHaveCount(2);

    $states = array_column($json['parallel_groups'], 'parallel_state');
    expect($states)->toContain('data_collection')
        ->and($states)->toContain('verification');

    $group = $json['parallel_groups'][array_search('data_collection', $states, true)];

    expect($group['combinations'])->toBe(1)
        ->and($group['regions'])->toHaveKeys(['retailer', 'customer_info'])
        ->and($group['regions']['retailer'])->toHaveCount(1)
        ->and($group['regions']['retailer'][0]['type'])->toBe('happy')
        ->and($group['regions']['retailer'][0]['signature'])->toBe('awaiting_vehicle→[VEHICLE_PROVIDED]→vehicle_ready');
});

test('json carries the region path types that only exist at region level', function (): void {
    $json  = runPathsJson(RegionBoundaryMachine::class);
    $group = $json['parallel_groups'][0];
    $types = [];

    foreach ($group['regions'] as $paths) {
        foreach ($paths as $path) {
            $types[] = $path['type'];
        }
    }

    expect($types)->toContain('region_exit')
        ->and($types)->toContain('region_deferred');
});

// ── Region path types in the console block ───────────────────────────────────

test('each region path is tagged with its own type', function (): void {
    // These types cannot appear in the per-type groups, which are built over
    // machine-level paths only, so the PARALLEL block is the only place they show.
    $output = runPaths(RegionBoundaryMachine::class);

    expect($output)->toContain('PARALLEL: collecting')
        ->and($output)->toContain('[region_exit]')
        ->and($output)->toContain('[region_deferred]');
});

test('a class that is not a machine is refused before its statics are touched', function (): void {
    // class_exists alone let any resolvable class through, and the next line called
    // `$class::definition()` on it — a stranger's static method, and an uncaught TypeError
    // where an exit code belonged. The commands accept a FILE PATH too, which is
    // require_once'd, so the class reaching this check is whatever the caller pointed at.
    $exit = Artisan::call('machine:paths', ['machine' => ContextManager::class]);

    expect($exit)->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain('Machine class not found');
});

test('a real machine still resolves', function (): void {
    // The control: the guard must not reject the thing it exists to admit.
    $exit = Artisan::call('machine:paths', ['machine' => ReentrantParallelMachine::class]);

    expect($exit)->toBe(Command::SUCCESS);
});
