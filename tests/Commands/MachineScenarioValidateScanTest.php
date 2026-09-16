<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Commands\MachineScenarioValidateCommand;

/**
 * scanForMachineClasses() is the fallback the sweep uses when Composer's classmap is
 * unavailable: it walks app/Machines and rebuilds the class names from the paths. The
 * package's own suite always has a classmap, so the public path never reaches it — which
 * is why it is driven directly here.
 *
 * The directory is per-process: parallel workers share one base_path(), and a fixed name
 * would have them create and delete each other's fixture mid-run.
 */
function machineScanDir(): string
{
    return base_path('app/Machines'.getmypid());
}

function writeScanFixture(string $relativePath, string $contents): void
{
    $path = machineScanDir().'/'.$relativePath;

    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), recursive: true);
    }

    file_put_contents($path, $contents);
}

beforeEach(function (): void {
    $namespace = 'App\\Machines'.getmypid();

    // The one file that should come back: right suffix, and a class that loads.
    writeScanFixture('OrderMachine.php', <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        class OrderMachine {}
        PHP);

    // Right suffix, but nothing declares the class the path implies.
    writeScanFixture('GhostMachine.php', "<?php\n\n// declares nothing\n");

    // Wrong suffix.
    writeScanFixture('OrderHelper.php', "<?php\n\nnamespace {$namespace};\n\nclass OrderHelper {}\n");

    // Not PHP at all.
    writeScanFixture('notes.txt', "not php\n");

    // Scenarios live beside machines and must not be mistaken for them.
    writeScanFixture('Scenarios/PaidMachine.php', "<?php\n\n// a scenario, not a machine\n");

    require_once machineScanDir().'/OrderMachine.php';
});

afterEach(function (): void {
    $dir = machineScanDir();

    if (!is_dir($dir)) {
        return;
    }

    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    ) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($dir);
});

it('returns only the Machine files whose class actually loads', function (): void {
    $command = new MachineScenarioValidateCommand();
    $method  = new ReflectionMethod($command, 'scanForMachineClasses');

    $found = $method->invoke($command, machineScanDir());

    expect(array_keys($found))->toBe(['App\\Machines'.getmypid().'\\OrderMachine'])
        ->and($found['App\\Machines'.getmypid().'\\OrderMachine'])
        ->toEndWith('OrderMachine.php');
});

it('returns nothing for a directory with no machines in it', function (): void {
    $command = new MachineScenarioValidateCommand();
    $method  = new ReflectionMethod($command, 'scanForMachineClasses');

    expect($method->invoke($command, machineScanDir().'/Scenarios'))->toBe([]);
});
