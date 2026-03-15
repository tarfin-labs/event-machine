<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\ScheduleDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScheduledMachines\ScheduledMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScheduledMachines\ExpiredApplicationsResolver;

it('stub ScheduledMachine loads with schedules', function (): void {
    $definition = ScheduledMachine::definition();

    expect($definition->parsedSchedules)
        ->toHaveCount(2)
        ->toHaveKeys(['CHECK_EXPIRY', 'DAILY_REPORT'])
        ->and($definition->parsedSchedules['CHECK_EXPIRY'])
        ->toBeInstanceOf(ScheduleDefinition::class)
        ->and($definition->parsedSchedules['CHECK_EXPIRY']->resolver)
        ->toBe(ExpiredApplicationsResolver::class)
        ->and($definition->parsedSchedules['CHECK_EXPIRY']->hasResolver())
        ->toBeTrue()
        ->and($definition->parsedSchedules['DAILY_REPORT']->hasResolver())
        ->toBeFalse();
});

it('stub ExpiredApplicationsResolver returns configured ids', function (): void {
    ExpiredApplicationsResolver::setUp(['root-1', 'root-2', 'root-3']);

    $resolver = new ExpiredApplicationsResolver();
    $ids      = $resolver();

    expect($ids->toArray())->toBe(['root-1', 'root-2', 'root-3']);
});
