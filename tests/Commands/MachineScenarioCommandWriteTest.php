<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;

/**
 * The write path of `machine:scenario` had no test at all. Every case in
 * MachineScenarioCommandTest either passes --dry-run or asserts failure before the write is
 * reached, so mkdir, file_put_contents, the path the command builds and --force were never
 * exercised -- and a mutation run, which removes the --dry-run branch and the file_exists
 * guard, scattered generated files across the repository and overwrote a committed stub.
 *
 * These tests scaffold against a machine class that lives in a TEMP directory. The command
 * derives its output directory from the machine's own file (dirname($machineFile).'/Scenarios'),
 * so a bare subclass placed in a temp dir moves the writes out of the repository without
 * stubbing out the production path under test.
 */
function makeTempScenarioMachine(): array
{
    $id        = bin2hex(random_bytes(6));
    $dir       = sys_get_temp_dir().'/em_scenario_write_'.$id;
    $namespace = 'EmScenarioWriteTmp\\N'.$id;
    $base      = ScenarioTestMachine::class;

    mkdir($dir, 0755, true);

    $file = $dir.'/TempScenarioMachine.php';
    file_put_contents(
        $file,
        "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\n"
        ."class TempScenarioMachine extends \\{$base}\n{\n}\n"
    );

    // The file is outside every autoload map, so class_exists() alone would never find it.
    require_once $file;

    return [
        'dir'       => $dir,
        'namespace' => $namespace,
        'class'     => $namespace.'\\TempScenarioMachine',
        'scenarios' => $dir.'/Scenarios',
    ];
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/em_scenario_write_*') ?: [] as $dir) {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($dir);
    }
});

test('writes the scenario file into Scenarios/ next to the machine class', function (): void {
    $tmp      = makeTempScenarioMachine();
    $expected = $tmp['scenarios'].'/AtApprovedScenario.php';

    expect(file_exists($expected))->toBeFalse();

    $this->artisan('machine:scenario', [
        'name'    => 'AtApproved',
        'machine' => $tmp['class'],
        'source'  => 'reviewing',
        'event'   => 'APPROVE',
        'target'  => 'approved',
    ])->assertSuccessful();

    expect(file_exists($expected))->toBeTrue();

    $content = (string) file_get_contents($expected);

    expect($content)
        ->toContain('namespace '.$tmp['namespace'].'\\Scenarios;')
        ->toContain('class AtApprovedScenario extends MachineScenario')
        ->toContain("'reviewing'")
        ->toContain("'approved'");
});

test('creates the Scenarios/ directory when it does not exist', function (): void {
    $tmp = makeTempScenarioMachine();

    expect(is_dir($tmp['scenarios']))->toBeFalse();

    $this->artisan('machine:scenario', [
        'name'    => 'AtApproved',
        'machine' => $tmp['class'],
        'source'  => 'reviewing',
        'event'   => 'APPROVE',
        'target'  => 'approved',
    ])->assertSuccessful();

    expect(is_dir($tmp['scenarios']))->toBeTrue();
});

test('refuses to overwrite an existing scenario file without --force', function (): void {
    $tmp  = makeTempScenarioMachine();
    $path = $tmp['scenarios'].'/AtApprovedScenario.php';

    $this->artisan('machine:scenario', [
        'name'    => 'AtApproved',
        'machine' => $tmp['class'],
        'source'  => 'reviewing',
        'event'   => 'APPROVE',
        'target'  => 'approved',
    ])->assertSuccessful();

    $first = (string) file_get_contents($path);

    $this->artisan('machine:scenario', [
        'name'    => 'AtApproved',
        'machine' => $tmp['class'],
        'source'  => 'reviewing',
        'event'   => 'REJECT',
        'target'  => 'rejected',
    ])->assertFailed();

    // The refusal has to leave the file alone, not merely report failure.
    expect((string) file_get_contents($path))->toBe($first);
});

test('--force overwrites an existing scenario file', function (): void {
    $tmp  = makeTempScenarioMachine();
    $path = $tmp['scenarios'].'/AtApprovedScenario.php';

    $this->artisan('machine:scenario', [
        'name'    => 'AtApproved',
        'machine' => $tmp['class'],
        'source'  => 'reviewing',
        'event'   => 'APPROVE',
        'target'  => 'approved',
    ])->assertSuccessful();

    expect((string) file_get_contents($path))->toContain("'approved'");

    $this->artisan('machine:scenario', [
        'name'    => 'AtApproved',
        'machine' => $tmp['class'],
        'source'  => 'reviewing',
        'event'   => 'REJECT',
        'target'  => 'rejected',
        '--force' => true,
    ])->assertSuccessful();

    expect((string) file_get_contents($path))->toContain("'rejected'");
});

test('--dry-run writes nothing to disk', function (): void {
    $tmp = makeTempScenarioMachine();

    $this->artisan('machine:scenario', [
        'name'      => 'AtApproved',
        'machine'   => $tmp['class'],
        'source'    => 'reviewing',
        'event'     => 'APPROVE',
        'target'    => 'approved',
        '--dry-run' => true,
    ])->assertSuccessful();

    expect(is_dir($tmp['scenarios']))->toBeFalse()
        ->and(file_exists($tmp['scenarios'].'/AtApprovedScenario.php'))->toBeFalse();
});
