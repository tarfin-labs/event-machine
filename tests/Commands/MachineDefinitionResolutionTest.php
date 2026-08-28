<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Fixtures\InvalidMachines\UndefinedMachine;
use Tarfinlabs\EventMachine\Fixtures\InvalidMachines\NullDefinitionMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ReentrantParallelMachine;

/**
 * Every command that turns a caller-supplied class name into a definition shares
 * ResolvesMachineDefinition. The guard used to be copy-pasted, and only one copy was
 * ever tested: reverting the other three to a bare class_exists left the suite at the
 * same green count. This covers all four, so the shared trait cannot regress silently.
 */

/**
 * @return array<string, mixed>
 */
function argsFor(string $command, string $machineClass): array
{
    // The --dry-run/--stdout flags keep the positive control from writing a file when the
    // guard correctly lets a real machine through; they change nothing about the guard.
    return match ($command) {
        'machine:scenario' => [
            'name'      => 'Probe',
            'machine'   => $machineClass,
            'source'    => 'idle',
            'event'     => 'START',
            'target'    => 'completed',
            '--dry-run' => true,
        ],
        'machine:xstate' => ['machine' => $machineClass, '--stdout' => true],
        default          => ['machine' => $machineClass],
    };
}

$commands = [
    'machine:paths'    => 'machine:paths',
    'machine:coverage' => 'machine:coverage',
    'machine:xstate'   => 'machine:xstate',
    'machine:scenario' => 'machine:scenario',
];

test('a class that is not a machine is refused before its statics are touched', function (string $command): void {
    // class_exists alone let any resolvable class through, and the next line called
    // `$class::definition()` on it — a stranger's static method, and an uncaught TypeError
    // where an exit code belonged.
    $exit = Artisan::call($command, argsFor($command, ContextManager::class));

    expect($exit)->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain('Machine class not found');
})->with($commands);

test('a machine that never defines a machine fails with its own reason', function (string $command): void {
    // is_subclass_of admits this class: Machine declares definition() as a thrower, so an
    // abstract or half-written subclass passes every structural check and then raises
    // MachineDefinitionNotFoundException from inside the call. That used to reach the user
    // as a stack trace — the same outcome the guard was added to prevent, one class further in.
    $exit = Artisan::call($command, argsFor($command, UndefinedMachine::class));

    expect($exit)->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain('The machine definition is not defined');
})->with($commands);

test('a definition() that returns null fails instead of being used', function (string $command): void {
    // The return type is `?MachineDefinition`, so null is legal and no exception announces it.
    $exit = Artisan::call($command, argsFor($command, NullDefinitionMachine::class));

    expect($exit)->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain('returned no machine definition');
})->with($commands);

test('a real machine is not refused by the guard', function (string $command): void {
    // The control: the guard must not reject the thing it exists to admit. Commands can
    // still fail further on for their own reasons (machine:coverage needs coverage data),
    // so this asserts only that none of the three resolution messages fired.
    Artisan::call($command, argsFor($command, ReentrantParallelMachine::class));
    $output = Artisan::output();

    expect($output)->not->toContain('Machine class not found')
        ->and($output)->not->toContain('The machine definition is not defined')
        ->and($output)->not->toContain('returned no machine definition');
})->with($commands);
