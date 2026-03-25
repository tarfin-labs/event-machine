<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Testing\InlineBehaviorFake;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\InlineBehaviorMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\IncrementAction;

afterEach(function (): void {
    IncrementAction::resetAllFakes();
});

// ═════════════════════════════════════════════════════════════
//  History Preservation
// ═════════════════════════════════════════════════════════════

it('records ACTION_START and ACTION_FINISH for faked inline actions', function (): void {
    $machineId = 'machine';

    TestMachine::create(InlineBehaviorMachine::class)
        ->faking(['processAction'])
        ->send('PROCESS')
        ->assertHistoryContains(
            "{$machineId}.action.processAction.start",
            "{$machineId}.action.processAction.finish",
        );
});

it('records GUARD_PASS for faked inline guard returning null (default)', function (): void {
    // Faked without explicit return → default no-op returns null
    // null !== false → guard passes
    $machineId = 'machine';

    TestMachine::create(InlineBehaviorMachine::class)
        ->faking(['isAllowedGuard'])
        ->send('PROCESS')
        ->assertState('active')
        ->assertHistoryContains("{$machineId}.guard.isAllowedGuard.pass");
});

it('records GUARD_FAIL for faked inline guard returning false via shouldReturn', function (): void {
    $machineId = 'machine';

    TestMachine::create(InlineBehaviorMachine::class)
        ->faking(['isAllowedGuard' => false])
        ->assertGuarded('PROCESS')
        ->assertHistoryContains("{$machineId}.guard.isAllowedGuard.fail");
});

// ═════════════════════════════════════════════════════════════
//  Entry/Exit Coverage
// ═════════════════════════════════════════════════════════════

it('intercepts faked inline entry action', function (): void {
    TestMachine::create(InlineBehaviorMachine::class)
        ->faking(['entryAction'])
        ->send('PROCESS')
        ->assertState('active')
        ->assertBehaviorRan('entryAction')
        ->assertContext('entryRan', false); // original sets true, fake no-op leaves false
});

it('intercepts faked inline exit action', function (): void {
    TestMachine::create(InlineBehaviorMachine::class)
        ->faking(['exitAction'])
        ->send('PROCESS')
        ->assertState('active')
        ->assertBehaviorRan('exitAction')
        ->assertContext('exitRan', false); // original sets true, fake no-op leaves false
});

// ═════════════════════════════════════════════════════════════
//  Calculator Coverage
// ═════════════════════════════════════════════════════════════

it('intercepts faked inline calculator', function (): void {
    TestMachine::create(InlineBehaviorMachine::class)
        ->faking(['doubleCountCalculator'])
        ->send('PROCESS')
        ->assertState('active')
        ->assertBehaviorRan('doubleCountCalculator')
        ->assertContext('count', 0); // original doubles count (0*2=0), but faked no-op also leaves 0
});

// ═════════════════════════════════════════════════════════════
//  Spy Mode
// ═════════════════════════════════════════════════════════════

it('spy records calls but runs original closure', function (): void {
    InlineBehaviorFake::spy('processAction');

    TestMachine::create(InlineBehaviorMachine::class)
        ->send('PROCESS')
        ->assertState('active')
        ->assertContext('processed', true); // original runs and sets processed=true

    InlineBehaviorFake::assertRan('processAction');
});

// ═════════════════════════════════════════════════════════════
//  Cleanup Isolation
// ═════════════════════════════════════════════════════════════

it('inline fakes do not leak between tests (test A: register fake)', function (): void {
    InlineBehaviorFake::fake('leakTestAction');
    expect(InlineBehaviorFake::isFaked('leakTestAction'))->toBeTrue();
});

it('inline fakes do not leak between tests (test B: verify clean)', function (): void {
    // afterEach calls resetAllFakes() which clears inline fakes
    expect(InlineBehaviorFake::isFaked('leakTestAction'))->toBeFalse();
});

// ═════════════════════════════════════════════════════════════
//  assertBehaviorRanWith Parameter Shape
// ═════════════════════════════════════════════════════════════

it('assertBehaviorRanWith for inline receives array parameter', function (): void {
    InlineBehaviorFake::spy('processAction');

    TestMachine::create(InlineBehaviorMachine::class)
        ->send('PROCESS')
        ->assertBehaviorRanWith('processAction', function (array $params): bool {
            // The first parameter injected into processAction is ContextManager
            return $params[0] instanceof ContextManager;
        });
});
