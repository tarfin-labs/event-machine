<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Support\Timer;
use PHPUnit\Framework\AssertionFailedError;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\AfterTimerMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\EveryTimerMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\EveryWithMaxMachine;

// ═══════════════════════════════════════════
//  advanceTimers() Tests
// ═══════════════════════════════════════════

it('advanceTimers triggers after timer and transitions machine', function (): void {
    AfterTimerMachine::test()
        ->assertState('awaiting_payment')
        ->advanceTimers(Timer::days(8))
        ->assertState('cancelled');
});

it('advanceTimers does NOT trigger before deadline', function (): void {
    AfterTimerMachine::test()
        ->assertState('awaiting_payment')
        ->advanceTimers(Timer::days(3))
        ->assertState('awaiting_payment');
});

it('advanceTimers chained: first no effect, then triggers', function (): void {
    AfterTimerMachine::test()
        ->assertState('awaiting_payment')
        ->advanceTimers(Timer::days(3))
        ->assertState('awaiting_payment')
        ->advanceTimers(Timer::days(8))
        ->assertState('cancelled');
});

it('advanceTimers on state with no timers has no effect', function (): void {
    AfterTimerMachine::test()
        ->send('PAY')
        ->assertState('processing')
        ->advanceTimers(Timer::days(30))
        ->assertState('processing');
});

it('advanceTimers with every timer fires event and stays in state', function (): void {
    EveryTimerMachine::test()
        ->assertState('active')
        ->advanceTimers(Timer::days(31))
        ->assertState('active')
        ->assertContext('billing_count', 1);
});

it('advanceTimers with every fires multiple times', function (): void {
    $test = EveryTimerMachine::test()
        ->assertState('active')
        ->advanceTimers(Timer::days(31))
        ->assertContext('billing_count', 1);

    // Second advance
    $test->advanceTimers(Timer::days(31))
        ->assertContext('billing_count', 2);
});

it('advanceTimers works in-memory when persistence is off', function (): void {
    AfterTimerMachine::test()
        ->withoutPersistence()
        ->assertState('awaiting_payment')
        ->advanceTimers(Timer::days(8))
        ->assertState('cancelled');
});

// ═══════════════════════════════════════════
//  assertHasTimer() Tests
// ═══════════════════════════════════════════

it('assertHasTimer passes for configured timer event', function (): void {
    AfterTimerMachine::test()
        ->assertState('awaiting_payment')
        ->assertHasTimer('ORDER_EXPIRED');
});

it('assertHasTimer fails for non-timer event', function (): void {
    AfterTimerMachine::test()
        ->assertState('awaiting_payment')
        ->assertHasTimer('PAY');
})->throws(AssertionFailedError::class, 'has none');

it('assertHasTimer fails for nonexistent event', function (): void {
    AfterTimerMachine::test()
        ->assertState('awaiting_payment')
        ->assertHasTimer('DOES_NOT_EXIST');
})->throws(AssertionFailedError::class, 'no transition exists');

it('assertHasTimer passes for every timer', function (): void {
    EveryTimerMachine::test()
        ->assertState('active')
        ->assertHasTimer('BILLING');
});

// ═══════════════════════════════════════════
//  assertTimerFired() / assertTimerNotFired() Tests
// ═══════════════════════════════════════════

it('assertTimerFired passes after timer fires', function (): void {
    AfterTimerMachine::test()
        ->advanceTimers(Timer::days(8))
        ->assertTimerFired('ORDER_EXPIRED')
        ->assertState('cancelled');
});

it('assertTimerNotFired passes when no sweep has run', function (): void {
    AfterTimerMachine::test()
        ->assertTimerNotFired('ORDER_EXPIRED')
        ->assertState('awaiting_payment');
});

it('assertTimerFired throws when timer has not fired', function (): void {
    AfterTimerMachine::test()
        ->assertTimerFired('ORDER_EXPIRED');
})->throws(AssertionFailedError::class, 'no record found');

it('assertTimerNotFired passes before deadline', function (): void {
    AfterTimerMachine::test()
        ->advanceTimers(Timer::days(3))
        ->assertTimerNotFired('ORDER_EXPIRED')
        ->assertState('awaiting_payment');
});

it('assertTimerNotFired fails after timer fires', function (): void {
    AfterTimerMachine::test()
        ->advanceTimers(Timer::days(8))
        ->assertTimerNotFired('ORDER_EXPIRED');
})->throws(AssertionFailedError::class, 'NOT have been fired');

// ═══════════════════════════════════════════
//  Full Lifecycle Tests
// ═══════════════════════════════════════════

it('full lifecycle: after timer cancels order', function (): void {
    AfterTimerMachine::test()
        ->assertState('awaiting_payment')
        ->assertHasTimer('ORDER_EXPIRED')
        ->assertTimerNotFired('ORDER_EXPIRED')
        ->advanceTimers(Timer::days(3))
        ->assertState('awaiting_payment')
        ->advanceTimers(Timer::days(8))
        ->assertState('cancelled')
        ->assertTimerFired('ORDER_EXPIRED')
        ->assertFinished();
});

it('full lifecycle: every timer runs billing cycles', function (): void {
    EveryTimerMachine::test()
        ->assertState('active')
        ->assertHasTimer('BILLING')
        ->advanceTimers(Timer::days(31))
        ->assertState('active')
        ->assertContext('billing_count', 1)
        ->advanceTimers(Timer::days(31))
        ->assertContext('billing_count', 2);
});

it('full lifecycle: every with max triggers then event', function (): void {
    $test = EveryWithMaxMachine::test()
        ->assertState('retrying')
        ->assertHasTimer('RETRY');

    // Fire 3 times
    for ($i = 0; $i < 3; $i++) {
        $test->advanceTimers(Timer::hours(7));
    }

    // After max, the machine should handle MAX_RETRIES → failed
    $test->advanceTimers(Timer::hours(7))
        ->assertState('failed')
        ->assertFinished();
});
