<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;
use Tarfinlabs\EventMachine\Analysis\PathCoverageTracker;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ReentrantParallelMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;

/**
 * @param  array<string, mixed>  $options
 *
 * @return array{code: int, output: string}
 */
function runWithOptions(string $command, array $options): array
{
    $code = Artisan::call($command, $options);

    return ['code' => $code, 'output' => Artisan::output()];
}

// ── A typo must stop the command, not quietly change what it did ─────────────

test('a non-numeric ceiling is rejected rather than read as zero', function (
    string $command,
    array $options,
    string $flag,
): void {
    // (int) 'abc' is 0, and every one of these options means something at zero: a ceiling
    // of zero truncates everything, an index of zero picks the first item. So a typo did
    // not fail — it silently changed the answer.
    $result = runWithOptions($command, $options);

    expect($result['code'])->toBe(Command::FAILURE)
        ->and($result['output'])->toContain("--{$flag} must be an integer");
})->with([
    'paths --max-paths'         => ['machine:paths', ['machine' => ReentrantParallelMachine::class, '--max-paths' => 'abc'], 'max-paths'],
    'paths --max-depth'         => ['machine:paths', ['machine' => ReentrantParallelMachine::class, '--max-depth' => 'abc'], 'max-depth'],
    'scenario --max-iterations' => ['machine:scenario', ['name' => 'AtX', 'machine' => ScenarioTestMachine::class, 'source' => 'reviewing', 'event' => 'APPROVE', 'target' => 'approved', '--max-iterations' => 'abc', '--dry-run' => true], 'max-iterations'],
    'scenario --path'           => ['machine:scenario', ['name' => 'AtX', 'machine' => ScenarioTestMachine::class, 'source' => 'reviewing', 'event' => 'APPROVE', 'target' => 'approved', '--path' => 'abc', '--dry-run' => true], 'path'],
    'validate --max-iterations' => ['machine:scenario-validate', ['machine' => ScenarioTestMachine::class, '--max-iterations' => 'abc'], 'max-iterations'],
    // Not a typo a human would type, but Artisan::call passes whatever it is given, and
    // the non-scalar arm is the one that would otherwise throw "Array to string
    // conversion" from inside its own error message instead of reporting the problem.
    'paths --max-paths as array' => ['machine:paths', ['machine' => ReentrantParallelMachine::class, '--max-paths' => []], 'max-paths'],
]);

test('a ceiling below its floor is rejected too', function (): void {
    // Zero is the value a typo lands on, so it must not be quietly accepted either.
    $result = runWithOptions('machine:paths', [
        'machine'     => ReentrantParallelMachine::class,
        '--max-paths' => '0',
    ]);

    expect($result['code'])->toBe(Command::FAILURE)
        ->and($result['output'])->toContain('--max-paths must be at least 1');
});

test('machine:coverage validates its ceilings before reporting anything', function (): void {
    $from = sys_get_temp_dir().'/em-numopt-'.bin2hex(random_bytes(6)).'.json';
    file_put_contents($from, json_encode([]));

    try {
        $result = runWithOptions('machine:coverage', [
            'machine'     => ReentrantParallelMachine::class,
            '--from'      => $from,
            '--max-depth' => 'abc',
        ]);

        expect($result['code'])->toBe(Command::FAILURE)
            ->and($result['output'])->toContain('--max-depth must be an integer')
            // The figure must not be printed alongside the rejection.
            ->and($result['output'])->not->toContain('Coverage:');
    } finally {
        @unlink($from);
        PathCoverageTracker::reset();
    }
});

test('a valid ceiling still passes, whether written as a string or an int', function (): void {
    // Console input arrives as a string; Artisan::call passes whatever the caller wrote.
    // An earlier version of the check accepted only strings and rejected every
    // programmatic call — the suite caught it immediately.
    foreach ([['--max-paths' => '50'], ['--max-paths' => 50]] as $options) {
        $result = runWithOptions('machine:paths', ['machine' => ReentrantParallelMachine::class] + $options);

        expect($result['code'])->toBe(Command::SUCCESS);
    }
});
