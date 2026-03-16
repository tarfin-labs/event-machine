<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScheduledMachines\ScheduledMachine;

// ═══════════════════════════════════════════
//  runSchedule() Tests
// ═══════════════════════════════════════════

it('runSchedule sends event inline and transitions machine', function (): void {
    ScheduledMachine::test()
        ->assertState('active')
        ->runSchedule('CHECK_EXPIRY')
        ->assertState('expired')
        ->assertFinished();
});

it('runSchedule works for root-level on schedule', function (): void {
    ScheduledMachine::test()
        ->assertState('active')
        ->runSchedule('DAILY_REPORT')
        ->assertState('active'); // DAILY_REPORT transitions back to active
});

it('runSchedule throws for nonexistent schedule', function (): void {
    ScheduledMachine::test()
        ->runSchedule('NONEXISTENT');
})->throws(AssertionFailedError::class, 'not defined');

// ═══════════════════════════════════════════
//  assertHasSchedule() Tests
// ═══════════════════════════════════════════

it('assertHasSchedule passes for defined schedule', function (): void {
    $test = ScheduledMachine::test()
        ->assertHasSchedule('CHECK_EXPIRY')
        ->assertHasSchedule('DAILY_REPORT');

    expect($test)->not->toBeNull();
});

it('assertHasSchedule throws for nonexistent schedule', function (): void {
    ScheduledMachine::test()
        ->assertHasSchedule('NONEXISTENT');
})->throws(AssertionFailedError::class, 'not defined');

// ═══════════════════════════════════════════
//  Combined Lifecycle Tests
// ═══════════════════════════════════════════

it('full lifecycle: assertHasSchedule then runSchedule', function (): void {
    ScheduledMachine::test()
        ->assertState('active')
        ->assertHasSchedule('CHECK_EXPIRY')
        ->runSchedule('CHECK_EXPIRY')
        ->assertState('expired')
        ->assertFinished();
});
