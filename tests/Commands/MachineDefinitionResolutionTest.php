<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Fixtures\InvalidMachines\UndefinedMachine;
use Tarfinlabs\EventMachine\Fixtures\InvalidMachines\NullDefinitionMachine;
use Tarfinlabs\EventMachine\Fixtures\InvalidMachines\ThrowingDefinitionMachine;
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
    'machine:paths'             => 'machine:paths',
    'machine:coverage'          => 'machine:coverage',
    'machine:xstate'            => 'machine:xstate',
    'machine:scenario'          => 'machine:scenario',
    'machine:scenario-validate' => 'machine:scenario-validate',
];

// The three commands that also accept a FILE PATH for the machine argument.
$fileCommands = [
    'machine:paths'    => 'machine:paths',
    'machine:coverage' => 'machine:coverage',
    'machine:xstate'   => 'machine:xstate',
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

test('a definition() that throws anything else fails with the class and the reason', function (string $command): void {
    // The catch was keyed on MachineDefinitionNotFoundException alone, so every other cause —
    // a missing behavior class, a bad config, a container resolve that fails — was uncaught in
    // all five commands. definition() is ordinary user code; it can throw anything.
    $exit = Artisan::call($command, argsFor($command, ThrowingDefinitionMachine::class));

    // Artisan::output() drains the buffer, so it is read once.
    $output = Artisan::output();

    expect($exit)->toBe(Command::FAILURE)
        ->and($output)->toContain('definition() failed')
        ->and($output)->toContain('App\\Actions\\Missing');
})->with($commands);

test('a directory given where a file was expected fails cleanly', function (string $command): void {
    // The easiest of these to hit by accident: str_contains(DIRECTORY_SEPARATOR) enters the
    // file-path branch and file_exists() is true for a directory, so file_get_contents() raised
    // `Read of N bytes failed with errno=21 Is a directory` — a stack trace, not an exit code.
    $exit = Artisan::call($command, ['machine' => 'src'.DIRECTORY_SEPARATOR.'Analysis']);

    expect($exit)->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain('Could not resolve a Machine class');
})->with($fileCommands);

test('an unreadable file fails cleanly', function (string $command): void {
    $token = getmypid().'_'.mt_rand();
    $path  = sys_get_temp_dir().'/em_unreadable_'.$token.'.php';
    file_put_contents($path, "<?php\nnamespace Probe{$token};\nclass P extends \\stdClass {}\n");
    chmod($path, 0o000);

    try {
        $exit = Artisan::call($command, ['machine' => $path]);

        expect($exit)->toBe(Command::FAILURE)
            ->and(Artisan::output())->toContain('not readable');
    } finally {
        chmod($path, 0o644);
        @unlink($path);
    }
})->with($fileCommands)->skip(
    posix_geteuid() === 0,
    'chmod 000 does not restrict root, so the unreadable branch cannot be reached.',
);

test('a file that cannot be parsed fails cleanly', function (string $command): void {
    // require_once runs before any check can look at the result, so a ParseError escaped. The
    // file must contain `class X extends` for the regex to produce an FQCN worth loading.
    $token = getmypid().'_'.mt_rand();
    $path  = sys_get_temp_dir().'/em_unparseable_'.$token.'.php';
    file_put_contents($path, "<?php\nnamespace ProbeBad{$token};\nclass Broken extends \\stdClass { this is not php }\n");

    try {
        $exit = Artisan::call($command, ['machine' => $path]);

        expect($exit)->toBe(Command::FAILURE)
            ->and(Artisan::output())->toContain('failed');
    } finally {
        @unlink($path);
    }
})->with($fileCommands);

test('a file that throws while loading fails cleanly', function (string $command): void {
    // The namespace has to be unique per invocation, not just the path: once one dataset row
    // has loaded the file, class_exists() is true for the rest of the process and the load is
    // skipped — which would make the later rows pass for the wrong reason.
    $token = getmypid().'_'.mt_rand();
    $path  = sys_get_temp_dir().'/em_throws_'.$token.'.php';
    file_put_contents(
        $path,
        "<?php\nnamespace ProbeThrow{$token};\nclass Boom extends \\stdClass {}\nthrow new \\RuntimeException('load-time boom');\n",
    );

    try {
        $exit = Artisan::call($command, ['machine' => $path]);

        expect($exit)->toBe(Command::FAILURE)
            ->and(Artisan::output())->toContain('load-time boom');
    } finally {
        @unlink($path);
    }
})->with($fileCommands);

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
