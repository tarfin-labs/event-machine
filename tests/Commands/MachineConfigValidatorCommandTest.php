<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Commands;

use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AbcMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Xyz\XyzMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

it('test it validates machine with valid config', function (): void {
    $this
        ->artisan('machine:validate', ['machine' => [class_basename(AbcMachine::class)]])
        ->expectsOutput("✓ Machine '".AbcMachine::class."' configuration is valid.")
        ->assertSuccessful();
});

it('test it shows error for non existent machine', function (): void {
    $this
        ->artisan('machine:validate', ['machine' => ['NonExistentMachine']])
        ->expectsOutput("Machine class 'NonExistentMachine' not found.")
        ->assertSuccessful();
});

it('test it validates all machines', function (): void {
    $this
        ->artisan('machine:validate', ['--all' => true])
        ->expectsOutputToContain("✓ Machine '".AbcMachine::class."' configuration is valid.")
        ->expectsOutputToContain("✓ Machine '".XyzMachine::class."' configuration is valid.")
        ->expectsOutputToContain("✓ Machine '".TrafficLightsMachine::class."' configuration is valid.")
        ->assertSuccessful();
});

it('test it requires machine argument or all option', function (): void {
    $this
        ->artisan(command: 'machine:validate')
        ->expectsOutput(output: 'Please provide a machine class name or use --all option.')
        ->assertSuccessful();
});
