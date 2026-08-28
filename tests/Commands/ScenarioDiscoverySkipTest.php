<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Tarfinlabs\EventMachine\Scenarios\ScenarioDiscovery;
use Tarfinlabs\EventMachine\Fixtures\BrokenScenarios\BrokenScenarioMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;

/**
 * Discovery has to skip a scenario file it cannot load or construct — one broken file must not
 * take down the rest. Doing it silently was the problem: the file left the list, the command
 * reported a smaller total, and exited 0. A broken scenario was indistinguishable from one
 * nobody had written.
 *
 * The broken files are written at run time rather than committed, because the argument-less
 * `machine:scenario-validate` sweep discovers this fixture too — a committed broken scenario
 * would make the package's own sweep exit 1 forever.
 */

/**
 * Absolute path to the fixture machine's Scenarios/ directory.
 */
function brokenScenarioDir(): string
{
    return dirname((string) (new ReflectionClass(BrokenScenarioMachine::class))->getFileName()).'/Scenarios';
}

beforeEach(function (): void {
    $dir = brokenScenarioDir();

    file_put_contents($dir.'/ThrowsOnConstructScenario.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Tarfinlabs\EventMachine\Fixtures\BrokenScenarios\Scenarios;

        use RuntimeException;
        use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
        use Tarfinlabs\EventMachine\Fixtures\BrokenScenarios\BrokenScenarioMachine;

        class ThrowsOnConstructScenario extends MachineScenario
        {
            protected string $machine     = BrokenScenarioMachine::class;
            protected string $source      = 'idle';
            protected string $event       = 'GO';
            protected string $target      = 'done';
            protected string $description = 'Loads, but cannot be constructed';

            public function __construct()
            {
                throw new RuntimeException('fixture: this scenario cannot be constructed');
            }

            protected function plan(): array
            {
                return [];
            }
        }
        PHP);

    // Deliberately NOT the namespace the path implies: discovery builds the FQCN from the
    // directory layout, so nothing loads under the name it expects. This is the shape a
    // namespace move leaves behind when the file is not moved with it.
    file_put_contents($dir.'/MismatchedNamespaceScenario.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Tarfinlabs\EventMachine\Fixtures\BrokenScenarios\SomewhereElse;

        use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
        use Tarfinlabs\EventMachine\Fixtures\BrokenScenarios\BrokenScenarioMachine;

        class MismatchedNamespaceScenario extends MachineScenario
        {
            protected string $machine     = BrokenScenarioMachine::class;
            protected string $source      = 'idle';
            protected string $event       = 'GO';
            protected string $target      = 'done';
            protected string $description = 'Declared under a namespace the path does not imply';

            protected function plan(): array
            {
                return [];
            }
        }
        PHP);

    // fixtures/ is autoloaded by CLASSMAP, which is generated at dump-autoload time, so a file
    // written now is invisible to class_exists() no matter what it declares. Requiring them
    // makes the two cases differ for the reason under test rather than for that one: the first
    // is then a class that loads and fails to construct, the second a file whose declared
    // namespace is not the one the path implies.
    require_once $dir.'/ThrowsOnConstructScenario.php';
    require_once $dir.'/MismatchedNamespaceScenario.php';

    ScenarioDiscovery::resetCache();
});

afterEach(function (): void {
    $dir = brokenScenarioDir();

    @unlink($dir.'/ThrowsOnConstructScenario.php');
    @unlink($dir.'/MismatchedNamespaceScenario.php');

    ScenarioDiscovery::resetCache();
});

test('a scenario that cannot be constructed is recorded with its reason', function (): void {
    $skipped = ScenarioDiscovery::skippedFor(BrokenScenarioMachine::class);

    $construct = array_values(array_filter(
        $skipped,
        static fn (array $s): bool => str_contains($s['class'], 'ThrowsOnConstructScenario'),
    ));

    expect($construct)->toHaveCount(1)
        ->and($construct[0]['reason'])->toContain('construction failed')
        ->and($construct[0]['reason'])->toContain('cannot be constructed')
        ->and($construct[0]['file'])->toContain('ThrowsOnConstructScenario.php');
});

test('a file whose class does not load under its expected name is recorded', function (): void {
    $skipped = ScenarioDiscovery::skippedFor(BrokenScenarioMachine::class);

    $notFound = array_values(array_filter(
        $skipped,
        static fn (array $s): bool => str_contains($s['class'], 'MismatchedNamespaceScenario'),
    ));

    expect($notFound)->toHaveCount(1)
        ->and($notFound[0]['reason'])->toContain('class not found')
        ->and($notFound[0]['file'])->toContain('MismatchedNamespaceScenario.php');
});

test('the scenario that does load is still returned', function (): void {
    // The control: skipping the broken two must not cost the good one.
    $scenarios = ScenarioDiscovery::forMachine(BrokenScenarioMachine::class);

    expect($scenarios)->toHaveCount(1)
        ->and($scenarios->first()::class)->toContain('WorkingScenario');
});

test('skippedFor is stable across repeated calls', function (): void {
    // forMachine() is not cached, so a list that accumulated would report the same broken
    // scenario once more on every call.
    $first  = ScenarioDiscovery::skippedFor(BrokenScenarioMachine::class);
    $second = ScenarioDiscovery::skippedFor(BrokenScenarioMachine::class);

    expect($first)->toHaveCount(2)
        ->and($second)->toHaveCount(2);
});

test('machine:scenario-validate names the files it could not load and fails', function (): void {
    $exit   = Artisan::call('machine:scenario-validate', ['machine' => BrokenScenarioMachine::class]);
    $output = Artisan::output();

    expect($exit)->toBe(Command::FAILURE)
        ->and($output)->toContain('3 scenario files found, 1 validated')
        ->and($output)->toContain('ThrowsOnConstructScenario')
        ->and($output)->toContain('MismatchedNamespaceScenario')
        ->and($output)->toContain('2 not loaded');
});

test('the sweep also fails when a machine has a scenario it could not load', function (): void {
    // The sweep totals its per-machine results separately from the single-machine path, so
    // both call sites need the skipped count or one of them keeps exiting 0.
    $exit = Artisan::call('machine:scenario-validate');

    expect($exit)->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain('not loaded');
});

test('a machine whose scenarios all load reports nothing skipped', function (): void {
    // The control for the command: the report must not fire for an ordinary machine.
    Artisan::call('machine:scenario-validate', ['machine' => ScenarioTestMachine::class]);
    $output = Artisan::output();

    expect($output)->not->toContain('not loaded')
        ->and($output)->not->toContain('could not be loaded');
});

test('a --scenario filter does not report the files it deliberately excluded', function (): void {
    // A filter is meant to narrow the list, so every other file being absent is the point.
    // Reporting them as skipped would make every filtered run look broken.
    Artisan::call('machine:scenario-validate', [
        'machine'    => BrokenScenarioMachine::class,
        '--scenario' => 'WorkingScenario',
    ]);

    expect(Artisan::output())->not->toContain('could not be loaded');
});
