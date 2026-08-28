<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;
use Tarfinlabs\EventMachine\Scenarios\ScenarioValidator;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;

/**
 * @param  array<string, mixed>  $options
 *
 * @return array{output: string, exit: int}
 */
function runScenario(array $options): array
{
    $exit = Artisan::call('machine:scenario', $options);

    return ['output' => Artisan::output(), 'exit' => $exit];
}

// ── The distinction the original bug report asked for ────────────────────────

test('an exhausted search reports that no path exists', function (): void {
    $result = runScenario([
        'name'      => 'AtBlocked',
        'machine'   => ScenarioTestMachine::class,
        'source'    => 'reviewing',
        'event'     => 'APPROVE',
        'target'    => 'blocked',
        '--dry-run' => true,
    ]);

    expect($result['output'])->toContain("No path from 'reviewing' to 'blocked'")
        ->and($result['output'])->not->toContain('truncated')
        ->and($result['exit'])->toBe(Command::FAILURE);
});

test('a capped search reports truncation instead of absence', function (): void {
    // Same target, same machine — only the search budget differs. Reporting these two
    // the same way is what made a stopped search look like a confident answer.
    $result = runScenario([
        'name'             => 'AtBlocked',
        'machine'          => ScenarioTestMachine::class,
        'source'           => 'reviewing',
        'event'            => 'START_PARALLEL',
        'target'           => 'blocked',
        '--max-iterations' => 1,
        '--dry-run'        => true,
    ]);

    expect($result['output'])->toContain('was truncated at the search limit')
        ->and($result['output'])->toContain('A path may still exist')
        ->and($result['output'])->not->toContain('No path from')
        ->and($result['exit'])->toBe(Command::FAILURE);
});

test('both outcomes still fail, so exit-code handling is unchanged', function (): void {
    $exhausted = runScenario([
        'name'      => 'A', 'machine' => ScenarioTestMachine::class,
        'source'    => 'reviewing', 'event' => 'APPROVE', 'target' => 'blocked',
        '--dry-run' => true,
    ]);

    $truncated = runScenario([
        'name'             => 'B', 'machine' => ScenarioTestMachine::class,
        'source'           => 'reviewing', 'event' => 'START_PARALLEL', 'target' => 'blocked',
        '--max-iterations' => 1, '--dry-run' => true,
    ]);

    expect($exhausted['exit'])->toBe($truncated['exit'])
        ->and($exhausted['exit'])->toBe(Command::FAILURE);
});

// ── Region-interior targets, the second reported bug ─────────────────────────

test('a target inside a parallel region scaffolds successfully', function (): void {
    $result = runScenario([
        'name'      => 'AtCheckingA',
        'machine'   => ScenarioTestMachine::class,
        'source'    => 'reviewing',
        'event'     => 'START_PARALLEL',
        'target'    => 'parallel_check.region_a.checking_a',
        '--dry-run' => true,
    ]);

    expect($result['exit'])->toBe(Command::SUCCESS)
        ->and($result['output'])->toContain('AtCheckingAScenario')
        ->and($result['output'])->toContain('parallel_check.region_a.checking_a')
        ->and($result['output'])->not->toContain('No path from');
});

// ScenarioValidator's side of this distinction is covered behaviourally in
// tests/Features/ScenarioValidatorTest.php, where the makeScenario() helper lives.

test('a truncated search that did find a path still says it was truncated', function (): void {
    // The truncation notice used to live inside the "no paths" branch only, so a search
    // that stopped with work pending but happened to find one route reported nothing at
    // all. The route list is then partial and --path=N indexes into a partial set, which
    // is exactly the "stopped looking" case the command is supposed to disclose.
    $result = runScenario([
        'name'             => 'AtCheckingA',
        'machine'          => ScenarioTestMachine::class,
        'source'           => 'reviewing',
        'event'            => 'START_PARALLEL',
        'target'           => 'parallel_check.region_a.checking_a',
        '--max-iterations' => 3,
        '--dry-run'        => true,
    ]);

    expect($result['output'])->toContain('truncated at the search limit')
        ->and($result['output'])->toContain('--max-iterations')
        ->and($result['output'])->not->toContain('No path from')
        ->and($result['exit'])->toBe(Command::SUCCESS);
});

test('an exhaustive search that found a path says nothing about truncation', function (): void {
    // Same route, budget raised past the point where the search completes. Without this
    // the warning above could be unconditional and the test would not notice.
    $result = runScenario([
        'name'             => 'AtCheckingA',
        'machine'          => ScenarioTestMachine::class,
        'source'           => 'reviewing',
        'event'            => 'START_PARALLEL',
        'target'           => 'parallel_check.region_a.checking_a',
        '--max-iterations' => 1000,
        '--dry-run'        => true,
    ]);

    expect($result['output'])->not->toContain('truncated')
        ->and($result['exit'])->toBe(Command::SUCCESS);
});
