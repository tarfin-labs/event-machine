<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Tarfinlabs\EventMachine\Fixtures\InvalidMachines\NonStaticGetTypeProbe;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;

/**
 * machine:scenario takes four caller-supplied strings and turns them into a file path, a class
 * name, generated PHP, and static calls on whatever the event argument names. Each was reachable.
 */

/**
 * @return array<string, mixed>
 */
function scenarioArgs(string $name, string $event = 'START_PARALLEL'): array
{
    return [
        'name'      => $name,
        'machine'   => ScenarioTestMachine::class,
        'source'    => 'reviewing',
        'event'     => $event,
        'target'    => 'all_checked',
        '--dry-run' => true,
    ];
}

test('a scenario name that escapes its directory is refused', function (string $name): void {
    // Measured before the fix: `../Foo` produced <machineDir>/Scenarios/../FooScenario.php — the
    // file was written OUTSIDE Scenarios/, the command printed "Created:" and exited 0. --force
    // was not required, because the existing-file check only guards overwrite.
    $exit = Artisan::call('machine:scenario', scenarioArgs($name));

    expect($exit)->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain('Invalid scenario name');
})->with([
    'parent traversal' => '../Foo',
    'nested traversal' => '../../Foo',
    'absolute path'    => '/tmp/Foo',
    'separator'        => 'Sub/Foo',
]);

test('a scenario name that is not a class name is refused', function (): void {
    // The name is interpolated verbatim into the generated file, so this produced
    // `class Evil{} echo 1; class ZzScenario extends MachineScenario` — top-level statements
    // in a PHP class file.
    $exit = Artisan::call('machine:scenario', scenarioArgs('Evil{} echo 1; class Zz'));

    expect($exit)->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain('Invalid scenario name');
});

test('a valid scenario name still passes the check', function (): void {
    // The control: the guard must not reject the thing it exists to admit. --dry-run keeps it
    // from writing, and the run may still fail for its own reasons, so only the name is asserted.
    Artisan::call('machine:scenario', scenarioArgs('Probe'));

    expect(Artisan::output())->not->toContain('Invalid scenario name');
});

test('an event argument that is not an EventBehavior is never invoked', function (): void {
    // class_exists() on the raw argument AUTOLOADS whatever it names, running that file's
    // top-level code before anything establishes it is an event, and method_exists() then
    // admitted a non-static getType(). Requiring an EventBehavior subclass settles both.
    NonStaticGetTypeProbe::$touched = false;

    $exit = Artisan::call('machine:scenario', scenarioArgs('Probe', NonStaticGetTypeProbe::class));

    // Whatever the command decides, it must decide it — not raise from inside the resolver.
    expect($exit)->toBeIn([Command::SUCCESS, Command::FAILURE])
        ->and(NonStaticGetTypeProbe::$touched)->toBeFalse();
});
