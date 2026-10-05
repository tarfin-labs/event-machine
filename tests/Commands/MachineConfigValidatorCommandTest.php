<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Commands;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Console\Scheduling\Schedule;
use Symfony\Component\Console\Command\Command;
use Tarfinlabs\EventMachine\Scheduling\MachineTimer;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AbcMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Xyz\XyzMachine;
use Tarfinlabs\EventMachine\Commands\MachineConfigValidatorCommand;
use Tarfinlabs\EventMachine\Fixtures\InvalidMachines\MiswiredContextMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\AfterTimerMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\EveryTimerMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\EveryWithMaxMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\LoopMachines\AlwaysLoopOnTimerMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\BehaviorCoverage\BehaviorCoverageMachine;

it('test it validates machine with valid config', function (): void {
    $this
        ->artisan('machine:validate', ['machine' => [class_basename(AbcMachine::class)]])
        ->expectsOutput("✓ Machine '".AbcMachine::class."' configuration is valid.")
        ->assertExitCode(Command::SUCCESS);
});

it('test it fails for non existent machine', function (): void {
    $this
        ->artisan('machine:validate', ['machine' => ['NonExistentMachine']])
        ->expectsOutput("Machine class 'NonExistentMachine' not found.")
        ->assertExitCode(Command::FAILURE);
});

it('test it reports a usage error without machine argument or all option', function (): void {
    $this
        ->artisan(command: 'machine:validate')
        ->expectsOutput(output: 'Please provide a machine class name or use --all option.')
        ->assertExitCode(Command::INVALID);
});

it('test it keeps validating the remaining named machines after a failure', function (): void {
    $this
        ->artisan('machine:validate', ['machine' => ['NonExistentMachine', class_basename(AbcMachine::class)]])
        ->expectsOutput("Machine class 'NonExistentMachine' not found.")
        ->expectsOutput("✓ Machine '".AbcMachine::class."' configuration is valid.")
        ->assertExitCode(Command::FAILURE);
});

it('test it reports a machine identically whether named or swept', function (): void {
    $line = "✓ Machine '".AbcMachine::class."' configuration is valid.";

    $this
        ->artisan('machine:validate', ['machine' => [class_basename(AbcMachine::class)]])
        ->expectsOutput($line)
        ->assertExitCode(Command::SUCCESS);

    // Every discoverable stub with timers gets its sweep, as an application's routes/console.php
    // would give it; without one, the sweep below rightly fails those machines.
    foreach ([
        AfterTimerMachine::class,
        EveryTimerMachine::class,
        EveryWithMaxMachine::class,
        AlwaysLoopOnTimerMachine::class,
        BehaviorCoverageMachine::class,
    ] as $timerMachine) {
        MachineTimer::register($timerMachine);
    }

    // The only full sweep in this file. A sweep builds every discoverable machine
    // definition and reflects over every behavior, so it is by far the most expensive
    // thing here — everything that needs one asserts against this single run.
    $this
        ->artisan('machine:validate', ['--all' => true])
        ->expectsOutputToContain($line)
        ->expectsOutputToContain("✓ Machine '".XyzMachine::class."' configuration is valid.")
        ->expectsOutputToContain("✓ Machine '".TrafficLightsMachine::class."' configuration is valid.")
        ->expectsOutputToContain('machine(s)')
        ->doesntExpectOutputToContain(MiswiredContextMachine::class)
        ->assertExitCode(Command::SUCCESS);
});

it('test it validates a machine named by its fully qualified class name', function (): void {
    $this
        ->artisan('machine:validate', ['machine' => [AbcMachine::class]])
        ->expectsOutput("✓ Machine '".AbcMachine::class."' configuration is valid.")
        ->assertExitCode(Command::SUCCESS);
});

it('test it fails and names the searched paths when discovery finds nothing', function (): void {
    $command = new class() extends MachineConfigValidatorCommand {
        protected function getSearchPaths(): array
        {
            return [__DIR__.'/../Stubs/Failures'];
        }
    };
    $command->setLaravel($this->app);

    $this->app[Kernel::class]->registerCommand($command);

    $this
        ->artisan($command->getName(), ['--all' => true])
        ->expectsOutputToContain('No machines discovered in:')
        ->assertExitCode(Command::FAILURE);
});

it('test it validates an invalid fixture named explicitly', function (): void {
    // Named explicitly: class-first resolution reaches a fixture the sweep never sees.
    // That the sweep does not see it is asserted once, in the single-sweep test above.
    $this
        ->artisan('machine:validate', ['machine' => [MiswiredContextMachine::class]])
        ->expectsOutputToContain('wiring problem(s):')
        ->assertExitCode(Command::FAILURE);
});

it('test it reports wiring findings grouped under the machine', function (): void {
    // A machine whose behaviors expect a context it does not declare: the engine would
    // TypeError on the first transition, which is exactly what the check prevents.
    $this
        ->artisan('machine:validate', ['machine' => [MiswiredContextMachine::class]])
        ->expectsOutputToContain('wiring problem(s):')
        ->expectsOutputToContain('declares context')
        ->assertExitCode(Command::FAILURE);
});

it('test it suppresses the success line for a machine with findings', function (): void {
    $this
        ->artisan('machine:validate', ['machine' => [MiswiredContextMachine::class]])
        ->doesntExpectOutputToContain("✓ Machine '".MiswiredContextMachine::class."' configuration is valid.")
        ->assertExitCode(Command::FAILURE);
});

it('test it fails a timer machine whose sweep is not scheduled', function (): void {
    // The TractorSalesMachine case: a 7-day timer that never fired because nothing
    // registered its sweep, and nothing said so.
    $this
        ->artisan('machine:validate', ['machine' => [AfterTimerMachine::class]])
        // One expectation: the finding is a single line, and each expectation consumes the line it matches.
        ->expectsOutputToContain('has after/every timers but no machine:process-timers sweep is scheduled for it, so its timers never fire. Register it with MachineTimer::register(\\'.AfterTimerMachine::class.'::class)')
        ->doesntExpectOutputToContain("✓ Machine '".AfterTimerMachine::class."' configuration is valid.")
        ->assertExitCode(Command::FAILURE);
});

it('test it passes a timer machine registered with MachineTimer::register', function (): void {
    MachineTimer::register(AfterTimerMachine::class)->everyFiveMinutes();

    $this
        ->artisan('machine:validate', ['machine' => [AfterTimerMachine::class]])
        ->expectsOutput("✓ Machine '".AfterTimerMachine::class."' configuration is valid.")
        ->assertExitCode(Command::SUCCESS);
});

it('test it fails a timer sweep scheduled with the class unquoted', function (): void {
    // What MachineTimer::register() itself scheduled from 9.3.0 to 9.20.0: /bin/sh strips
    // the backslashes, so the sweep is registered and still never runs.
    resolve(Schedule::class)->command('machine:process-timers --class='.AfterTimerMachine::class);

    $this
        ->artisan('machine:validate', ['machine' => [AfterTimerMachine::class]])
        ->expectsOutputToContain('has a timer sweep scheduled with the class unquoted')
        ->assertExitCode(Command::FAILURE);
});

it('test it accepts a timer sweep scheduled by hand with the class quoted', function (string $commandLine): void {
    resolve(Schedule::class)->command($commandLine);

    $this
        ->artisan('machine:validate', ['machine' => [AfterTimerMachine::class]])
        ->expectsOutput("✓ Machine '".AfterTimerMachine::class."' configuration is valid.")
        ->assertExitCode(Command::SUCCESS);
})->with([
    'single quotes'   => ["machine:process-timers --class='".AfterTimerMachine::class."'"],
    'double quotes'   => ['machine:process-timers --class="'.AfterTimerMachine::class.'"'],
    'space separator' => ["machine:process-timers --class '".AfterTimerMachine::class."'"],
]);

it('test it passes a timer machine once any of its sweeps can run', function (): void {
    resolve(Schedule::class)->command('machine:process-timers --class='.AfterTimerMachine::class);
    MachineTimer::register(AfterTimerMachine::class);

    $this
        ->artisan('machine:validate', ['machine' => [AfterTimerMachine::class]])
        ->expectsOutput("✓ Machine '".AfterTimerMachine::class."' configuration is valid.")
        ->assertExitCode(Command::SUCCESS);
});

it('test it does not count a sweep scheduled for another machine', function (): void {
    MachineTimer::register(EveryTimerMachine::class);

    $this
        ->artisan('machine:validate', ['machine' => [AfterTimerMachine::class]])
        ->expectsOutputToContain('no machine:process-timers sweep is scheduled for it')
        ->assertExitCode(Command::FAILURE);
});

it('test it does not require a sweep for a machine without timers', function (): void {
    expect(resolve(Schedule::class)->events())->toBe([]);

    $this
        ->artisan('machine:validate', ['machine' => [TrafficLightsMachine::class]])
        ->expectsOutput("✓ Machine '".TrafficLightsMachine::class."' configuration is valid.")
        ->assertExitCode(Command::SUCCESS);
});
