<?php

declare(strict_types=1);

use Illuminate\Console\Application;
use Illuminate\Support\ProcessUtils;
use Symfony\Component\Process\Process;
use Tarfinlabs\EventMachine\Scheduling\MachineTimer;
use Tarfinlabs\EventMachine\Scheduling\MachineScheduler;
use Illuminate\Console\Scheduling\Event as SchedulingEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScheduledMachines\ScheduledMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

/*
 * The scheduler never calls a command through Artisan::call(): it builds a command line and
 * hands it to /bin/sh. Every other test reaches the sweep through Artisan, which skips the shell
 * entirely — which is how an unquoted --class (sh eats the backslashes of a namespaced FQCN)
 * shipped in 9.3.0 and left every MachineTimer::register() sweep failing in production, unseen,
 * because the scheduler sends its output to /dev/null. These tests put the shell back.
 */

/**
 * The arguments the scheduler passes after `php artisan`, exactly as they appear on the
 * command line it builds.
 */
function scheduledArtisanArguments(SchedulingEvent $event): string
{
    $prefix = Application::formatCommandString('');

    expect($event->command)->toStartWith($prefix)
        ->and($event->buildCommand())->toContain($event->command);

    return substr($event->command, strlen($prefix));
}

/**
 * Run a script through /bin/sh with the scheduler's own arguments appended verbatim.
 */
function runThroughShell(string $script, string $arguments, string $commandOverride = ''): Process
{
    $commandLine = ProcessUtils::escapeArgument(PHP_BINARY).' '.$script.' '.($commandOverride !== '' ? $commandOverride : $arguments);

    $process = Process::fromShellCommandline($commandLine, dirname(__DIR__, 2));
    $process->setTimeout(60.0);
    $process->run();

    return $process;
}

/**
 * The argv a shell delivers to the scheduled command.
 *
 * @return list<string>
 */
function shellArgvFor(SchedulingEvent $event): array
{
    $process = runThroughShell(
        ProcessUtils::escapeArgument(__DIR__.'/../Support/dump-argv.php'),
        scheduledArtisanArguments($event),
    );

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('MachineTimer::register delivers a namespaced class to the sweep intact through the shell', function (): void {
    $event = MachineTimer::register('App\\Machines\\Foo\\BarMachine');

    expect(shellArgvFor($event))->toBe([
        'machine:process-timers',
        '--class=App\\Machines\\Foo\\BarMachine',
    ]);
});

it('MachineScheduler::register delivers a namespaced class and the event to the command intact through the shell', function (): void {
    $event = MachineScheduler::register(ScheduledMachine::class, 'CHECK_EXPIRY');

    expect(shellArgvFor($event))->toBe([
        'machine:process-scheduled',
        '--class='.ScheduledMachine::class,
        '--event=CHECK_EXPIRY',
    ]);
});

it('the timer sweep the scheduler builds resolves the machine class when run through the shell', function (): void {
    $artisan   = ProcessUtils::escapeArgument(__DIR__.'/../Support/artisan.php');
    $arguments = scheduledArtisanArguments(MachineTimer::register(TrafficLightsMachine::class));

    $process = runThroughShell($artisan, $arguments);

    expect($process->getOutput().$process->getErrorOutput())->not->toContain('Failed to load definition')
        ->and($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());

    // Control: the same sweep with the class unquoted — what 9.3.0 through 9.20.0 scheduled —
    // must fail here, or this test could not have caught the bug it exists for.
    $unquoted = runThroughShell($artisan, $arguments, 'machine:process-timers --class='.TrafficLightsMachine::class);

    expect($unquoted->getExitCode())->toBe(1)
        ->and($unquoted->getOutput())->toContain('Failed to load definition for TarfinlabsEventMachine');
});
