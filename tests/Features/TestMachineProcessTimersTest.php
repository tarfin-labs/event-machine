<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Support\Timer;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Models\MachineTimerFire;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\AfterTimerMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\EveryTimerMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\EveryWithMaxMachine;

/**
 * processTimers() is the persistence-backed half of the timer test API: it runs the sweep
 * against machine_current_states / machine_timer_fires without moving the clock, which is
 * what advanceTimers() delegates to once a machine persists.
 *
 * Every Machine::test() entry point sets shouldPersist = false, so advanceTimers() always
 * took the in-memory branch and this path had no test at all — the documented example was
 * marked no_run, so nothing executed it either.
 */

/**
 * Persist the machine and hand back its root event id.
 */
function persistedRootEventId(TestMachine $test): string
{
    $test->machine()->persist();

    return $test->machine()->state->history->first()->root_event_id;
}

/**
 * Move the recorded state entry back in time, as an aged machine would look on disk.
 */
function backdateEntry(string $rootEventId, int $seconds): void
{
    MachineCurrentState::forInstance($rootEventId)
        ->update(['state_entered_at' => now()->subSeconds($seconds)]);
}

it('fires an after timer whose deadline has passed', function (): void {
    $test        = AfterTimerMachine::test();
    $rootEventId = persistedRootEventId($test);

    backdateEntry($rootEventId, Timer::days(8)->inSeconds());

    $test->processTimers()->assertState('cancelled');
});

it('leaves an after timer alone before its deadline', function (): void {
    $test        = AfterTimerMachine::test();
    $rootEventId = persistedRootEventId($test);

    backdateEntry($rootEventId, Timer::days(3)->inSeconds());

    $test->processTimers()->assertState('awaiting_payment');
});

it('does not fire the same after timer twice', function (): void {
    $test        = AfterTimerMachine::test();
    $rootEventId = persistedRootEventId($test);

    backdateEntry($rootEventId, Timer::days(8)->inSeconds());

    $test->processTimers()->processTimers();

    expect(MachineTimerFire::where('root_event_id', $rootEventId)->sum('fire_count'))->toBe(1);
});

it('fires an every timer and records the fire', function (): void {
    $test        = EveryTimerMachine::test();
    $rootEventId = persistedRootEventId($test);

    backdateEntry($rootEventId, Timer::days(31)->inSeconds());

    $test->processTimers()
        ->assertState('active')
        ->assertContext('billingCount', 1);

    expect(MachineTimerFire::where('root_event_id', $rootEventId)->value('fire_count'))->toBe(1);
});

it('holds an every timer inside its interval', function (): void {
    $test        = EveryTimerMachine::test();
    $rootEventId = persistedRootEventId($test);

    backdateEntry($rootEventId, Timer::days(31)->inSeconds());
    $test->processTimers()->assertContext('billingCount', 1);

    // The fire was just recorded, so the next sweep is inside the 30-day interval.
    $test->processTimers()->assertContext('billingCount', 1);
});

it('sends the then event once an every timer reaches its max', function (): void {
    $test        = EveryWithMaxMachine::test();
    $rootEventId = persistedRootEventId($test);

    // max => 3, so three sweeps fire RETRY and the fourth converts to MAX_RETRIES.
    for ($fire = 0; $fire < 4; $fire++) {
        backdateEntry($rootEventId, Timer::hours(7)->inSeconds());

        MachineTimerFire::where('root_event_id', $rootEventId)
            ->update(['last_fired_at' => now()->subHours(7)]);

        $test->processTimers();
    }

    $test->assertState('failed')->assertContext('retryCount', 3);
});

it('stops sweeping an exhausted every timer', function (): void {
    $test        = EveryWithMaxMachine::test();
    $rootEventId = persistedRootEventId($test);

    // Fire once so the row exists under the key the definition actually derives,
    // then exhaust it — guessing the key would test the guess, not the sweep.
    backdateEntry($rootEventId, Timer::hours(7)->inSeconds());
    $test->processTimers()->assertContext('retryCount', 1);

    MachineTimerFire::where('root_event_id', $rootEventId)->update([
        'status'        => MachineTimerFire::STATUS_EXHAUSTED,
        'last_fired_at' => now()->subDays(1),
    ]);

    backdateEntry($rootEventId, Timer::hours(7)->inSeconds());

    $test->processTimers()->assertState('retrying')->assertContext('retryCount', 1);
});

it('is a no-op for a state that has no timers', function (): void {
    $test = AfterTimerMachine::test()->send('PAY')->assertState('processing');

    $rootEventId = persistedRootEventId($test);
    backdateEntry($rootEventId, Timer::days(30)->inSeconds());

    $test->processTimers()->assertState('processing');
});
