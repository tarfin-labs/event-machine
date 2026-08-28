<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;

/**
 * Build a throwaway machine class in its own directory and return [dir, FQCN].
 *
 * The command derives the scenario directory from the machine class's own file
 * (`dirname($machineFile).'/Scenarios'`), so the only way to exercise its write
 * failures is to own that directory. A temp dir keeps the package's own stubs out
 * of it. Each caller passes a distinct suffix because a class cannot be declared
 * twice in one process.
 *
 * @return array{0: string, 1: class-string}
 */
function makeScenarioWriteProbe(string $suffix): array
{
    $unique = bin2hex(random_bytes(4));
    $dir    = sys_get_temp_dir().'/em-scenario-write-'.$suffix.'-'.$unique;
    mkdir($dir, 0755, true);

    // The random part belongs in the namespace as well as the directory. With only the
    // caller's suffix distinguishing them, a second run inside the same process — a
    // --repeat, or a retry after a failure — would redeclare the class and fatal.
    $namespace = 'EmScenarioWriteProbe'.$suffix.$unique;
    $file      = $dir.'/ProbeMachine.php';

    file_put_contents($file, <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Tarfinlabs\\EventMachine\\Actor\\Machine;
        use Tarfinlabs\\EventMachine\\Definition\\MachineDefinition;

        class ProbeMachine extends Machine
        {
            public static function definition(): MachineDefinition
            {
                return MachineDefinition::define(config: [
                    'id'      => 'write_probe',
                    'initial' => 'idle',
                    'states'  => [
                        'idle' => ['on' => ['GO' => 'done']],
                        'done' => ['type' => 'final'],
                    ],
                ]);
            }
        }
        PHP);

    require_once $file;

    return [$dir, $namespace.'\\ProbeMachine'];
}

function removeScenarioWriteProbe(string $dir): void
{
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
}

// ── The two write failures the command reports ───────────────────────────────

test('a scenario directory that cannot be created is reported, not assumed', function (): void {
    // Reporting "Created:" for a write that never happened sends the caller looking for
    // a file that is not there. Deterministic setup: something that is NOT a directory
    // already occupies the path, so mkdir fails without depending on file permissions
    // (a permission-based test passes vacuously when the suite runs as root).
    [$dir, $machine] = makeScenarioWriteProbe('Dir');

    file_put_contents($dir.'/Scenarios', 'not a directory');

    try {
        $exit = Artisan::call('machine:scenario', [
            'name'    => 'AtDone',
            'machine' => $machine,
            'source'  => 'idle',
            'event'   => 'GO',
            'target'  => 'done',
        ]);
        $output = Artisan::output();

        expect($exit)->toBe(Command::FAILURE)
            ->and($output)->toContain('Could not create scenario directory')
            ->and($output)->not->toContain('Created:');
    } finally {
        removeScenarioWriteProbe($dir);
    }
});

test('a scenario file that cannot be written is reported, not assumed', function (): void {
    // Same shape one level down: the target path exists but is a directory, so
    // file_put_contents fails. --force is needed because the path does exist, which is
    // what makes this the write branch rather than the "file already exists" branch.
    [$dir, $machine] = makeScenarioWriteProbe('File');

    mkdir($dir.'/Scenarios', 0755, true);
    mkdir($dir.'/Scenarios/AtDoneScenario.php', 0755, true);

    try {
        $exit = Artisan::call('machine:scenario', [
            'name'    => 'AtDone',
            'machine' => $machine,
            'source'  => 'idle',
            'event'   => 'GO',
            'target'  => 'done',
            '--force' => true,
        ]);
        $output = Artisan::output();

        expect($exit)->toBe(Command::FAILURE)
            ->and($output)->toContain('Could not write scenario file')
            ->and($output)->not->toContain('Created:');
    } finally {
        removeScenarioWriteProbe($dir);
    }
});

test('the same probe writes its scenario when nothing is in the way', function (): void {
    // Without this the two tests above could pass against a command that always fails.
    [$dir, $machine] = makeScenarioWriteProbe('Ok');

    try {
        $exit = Artisan::call('machine:scenario', [
            'name'    => 'AtDone',
            'machine' => $machine,
            'source'  => 'idle',
            'event'   => 'GO',
            'target'  => 'done',
        ]);
        $output = Artisan::output();

        expect($exit)->toBe(Command::SUCCESS)
            ->and($output)->toContain('Created:')
            ->and(is_file($dir.'/Scenarios/AtDoneScenario.php'))->toBeTrue();
    } finally {
        removeScenarioWriteProbe($dir);
    }
});
