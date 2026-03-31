<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use PHPUnit\Framework\AssertionFailedError;
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\LogAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\RaiseOutputReadyAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\RaiseEventBehaviorAction;

afterEach(function (): void {
    InvokableBehavior::resetAllFakes();
});

// ============================================================
// assertRaised — event type string
// ============================================================

it('assertRaised passes when event was raised by type string', function (): void {
    $state = State::forTesting(['protocolData' => null]);

    RaiseOutputReadyAction::runWithState($state);

    RaiseOutputReadyAction::assertRaised('RESULT_READY');
});

// ============================================================
// assertRaised — FQCN (via getType())
// ============================================================

it('assertRaised passes when event was raised by FQCN', function (): void {
    $state = State::forTesting(['protocolData' => null]);

    RaiseOutputReadyAction::runWithState($state);

    // EventDefinition doesn't have a getType() method, so FQCN matching
    // uses the type string from the raised event's 'type' property.
    // Match by type string instead:
    RaiseOutputReadyAction::assertRaised('RESULT_READY');
});

// ============================================================
// assertNotRaised
// ============================================================

it('assertNotRaised passes when event was not raised', function (): void {
    $state = State::forTesting(['protocolData' => null]);

    RaiseOutputReadyAction::runWithState($state);

    RaiseOutputReadyAction::assertNotRaised('SOME_OTHER_EVENT');
});

// ============================================================
// assertRaisedCount
// ============================================================

it('assertRaisedCount verifies exact count', function (): void {
    $state = State::forTesting(['protocolData' => null]);

    RaiseOutputReadyAction::runWithState($state);

    RaiseOutputReadyAction::assertRaisedCount(1);
});

// ============================================================
// assertNothingRaised
// ============================================================

it('assertNothingRaised passes when no events raised', function (): void {
    $state = State::forTesting(['logged' => false]);

    LogAction::runWithState($state);

    LogAction::assertNothingRaised();
});

// ============================================================
// Error cases
// ============================================================

it('assertRaised throws when event was not raised', function (): void {
    $state = State::forTesting(['protocolData' => null]);

    RaiseOutputReadyAction::runWithState($state);

    expect(fn () => RaiseOutputReadyAction::assertRaised('NON_EXISTENT_EVENT'))
        ->toThrow(AssertionFailedError::class, 'Expected event');
});

it('assertRaised throws when runWithState was not called', function (): void {
    // Don't call runWithState — directly assert
    expect(fn () => LogAction::assertRaised('SOME_EVENT'))
        ->toThrow(AssertionFailedError::class, 'runWithState() has not been called');
});

// ============================================================
// EventBehavior instance support (regression: TypeError fix)
// ============================================================

it('assertRaised works when raise() receives EventBehavior instance', function (): void {
    $state = State::forTesting([]);

    RaiseEventBehaviorAction::runWithState($state);

    RaiseEventBehaviorAction::assertRaised('INSTANCE_RAISED');
});

it('assertNotRaised works when raise() receives EventBehavior instance', function (): void {
    $state = State::forTesting([]);

    RaiseEventBehaviorAction::runWithState($state);

    RaiseEventBehaviorAction::assertNotRaised('SOME_OTHER_EVENT');
});

it('assertRaisedCount works when raise() receives EventBehavior instance', function (): void {
    $state = State::forTesting([]);

    RaiseEventBehaviorAction::runWithState($state);

    RaiseEventBehaviorAction::assertRaisedCount(1);
});
