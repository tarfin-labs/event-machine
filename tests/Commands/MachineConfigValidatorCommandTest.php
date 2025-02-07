<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Commands;

use Tarfinlabs\EventMachine\Tests\TestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AbcMachine;

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
}
