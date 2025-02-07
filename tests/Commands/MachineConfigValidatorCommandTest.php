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
}
