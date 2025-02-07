<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Commands;

use Tarfinlabs\EventMachine\Tests\TestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AbcMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Xyz\XyzMachine;

class MachineConfigValidatorCommandTest extends TestCase
{
    public function test_it_validates_machine_with_valid_config(): void
    {
        $this
            ->artisan('machine:validate', ['machine' => [class_basename(AbcMachine::class)]])
            ->expectsOutput("✓ Machine '".AbcMachine::class."' configuration is valid.")
            ->assertSuccessful();
    }

    public function test_it_shows_error_for_non_existent_machine(): void
    {
        $this
            ->artisan('machine:validate', ['machine' => ['NonExistentMachine']])
            ->expectsOutput("Machine class 'NonExistentMachine' not found.")
            ->assertSuccessful();
    }

    public function test_it_validates_all_machines(): void
    {
        $this
            ->artisan('machine:validate', ['--all' => true])
            ->expectsOutput("✓ Machine '".AbcMachine::class."' configuration is valid.")
            ->expectsOutput("✓ Machine '".XyzMachine::class."' configuration is valid.")
            ->assertSuccessful();
    }

    public function test_it_requires_machine_argument_or_all_option(): void
    {
        $this
            ->artisan(command: 'machine:validate')
            ->expectsOutput(output: 'Please provide a machine class name or use --all option.')
            ->assertSuccessful();
    }
}
