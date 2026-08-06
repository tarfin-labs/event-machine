<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Commands;

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Command\Command;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AbcMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Xyz\XyzMachine;
use Tarfinlabs\EventMachine\Commands\MachineConfigValidatorCommand;
use Tarfinlabs\EventMachine\Fixtures\InvalidMachines\MiswiredContextMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

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
