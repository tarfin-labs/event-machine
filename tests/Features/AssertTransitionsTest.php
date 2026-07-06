<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use Tarfinlabs\EventMachine\Analysis\PathCoverageTracker;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\RecordAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TransitionTableMachine;

it('verifies a passing transition table', function (): void {
    TransitionTableMachine::assertTransitions([
        ['from' => 'idle', 'event' => 'START', 'to' => 'working'],
        ['from' => 'working', 'event' => 'FINISH', 'to' => 'completed'],
        ['from' => 'idle', 'event' => 'GUARDED_START', 'to' => null, 'guarded' => true],
    ]);
});

it('fails a target mismatch with row information', function (): void {
    expect(fn () => TransitionTableMachine::assertTransitions([
        ['from' => 'idle', 'event' => 'START', 'to' => 'completed'],
    ]))->toThrow(AssertionFailedError::class, 'Row 0: expected [idle] --[START]--> [completed]');
});

it('runs rows in order and aborts on the first failure', function (): void {
    try {
        TransitionTableMachine::assertTransitions([
            ['from' => 'idle', 'event' => 'START', 'to' => 'working'],
            ['from' => 'working', 'event' => 'FINISH', 'to' => 'idle'],
            ['from' => 'idle', 'event' => 'START', 'to' => 'nonexistent'],
        ]);
        $this->fail('Expected an assertion failure.');
    } catch (AssertionFailedError $error) {
        expect($error->getMessage())->toContain('Row 1');
    }
});

it('accepts guarded rows blocked by a regular guard', function (): void {
    // allowed=false (default) → isAllowedGuard fails → TRANSITION_FAIL, machine stays
    TransitionTableMachine::assertTransitions([
        ['from' => 'idle', 'event' => 'GUARDED_START', 'to' => null, 'guarded' => true],
    ]);
});

it('accepts guarded rows rejected by a validation guard', function (): void {
    TransitionTableMachine::assertTransitions([
        ['from' => 'idle', 'event' => 'VALIDATED_START', 'to' => null, 'guarded' => true],
    ]);
});

it('fails non-guarded rows rejected by a validation guard', function (): void {
    expect(fn () => TransitionTableMachine::assertTransitions([
        ['from' => 'idle', 'event' => 'VALIDATED_START', 'to' => 'working'],
    ]))->toThrow(AssertionFailedError::class, 'rejected by a validation guard');
});

it('fails a guarded row whose transition succeeds', function (): void {
    expect(fn () => TransitionTableMachine::assertTransitions([
        ['from' => 'idle', 'event' => 'GUARDED_START', 'to' => null, 'guarded' => true, 'context' => ['allowed' => true]],
    ]))->toThrow(AssertionFailedError::class, 'expected transition for [GUARDED_START] from [idle] to be blocked');
});

it('fails unhandled events for non-guarded rows', function (): void {
    expect(fn () => TransitionTableMachine::assertTransitions([
        ['from' => 'idle', 'event' => 'NOT_AN_EVENT', 'to' => 'working'],
    ]))->toThrow(AssertionFailedError::class, 'is not handled from state [idle]');
});

it('fails unhandled events for guarded rows too', function (): void {
    expect(fn () => TransitionTableMachine::assertTransitions([
        ['from' => 'idle', 'event' => 'NOT_AN_EVENT', 'to' => null, 'guarded' => true],
    ]))->toThrow(AssertionFailedError::class, 'is not handled from state [idle]');
});

it('fails guard-blocked non-guarded rows even when to equals from', function (): void {
    // SELF_LOOP is guard-blocked (allowed=false) — the machine stays in idle,
    // but the row must NOT pass vacuously just because to == from.
    expect(fn () => TransitionTableMachine::assertTransitions([
        ['from' => 'idle', 'event' => 'SELF_LOOP', 'to' => 'idle'],
    ]))->toThrow(AssertionFailedError::class, 'was blocked by a guard');
});

it('merges row-level context over the shared context', function (): void {
    TransitionTableMachine::assertTransitions([
        // row context wins: allowed=true lets the guarded transition through
        ['from' => 'idle', 'event' => 'GUARDED_START', 'to' => 'working', 'context' => ['allowed' => true]],
        // no row context: shared allowed=false applies, guard blocks
        ['from' => 'idle', 'event' => 'GUARDED_START', 'to' => null, 'guarded' => true],
    ], context: ['allowed' => false]);
});

it('isolates rows with a fresh machine per row', function (): void {
    // Both rows start from idle — if state leaked between rows, the second
    // START would be unhandled from working and the table would fail.
    TransitionTableMachine::assertTransitions([
        ['from' => 'idle', 'event' => 'START', 'to' => 'working'],
        ['from' => 'idle', 'event' => 'START', 'to' => 'working'],
    ]);
});

it('applies the faking list to every row', function (): void {
    RecordAction::reset();

    TransitionTableMachine::assertTransitions([
        ['from' => 'idle', 'event' => 'RECORDED_START', 'to' => 'working'],
    ], faking: [RecordAction::class]);

    expect(RecordAction::wasExecuted())->toBeFalse();
});

it('rejects an empty table', function (): void {
    expect(fn () => TransitionTableMachine::assertTransitions([]))
        ->toThrow(InvalidArgumentException::class, 'at least one row');
});

it('rejects rows missing required keys', function (): void {
    expect(fn () => TransitionTableMachine::assertTransitions([
        ['from' => 'idle', 'event' => 'START'],
    ]))->toThrow(InvalidArgumentException::class, 'row 0 is missing the [to] key');
});

it('rejects guarded rows with a non-null target', function (): void {
    expect(fn () => TransitionTableMachine::assertTransitions([
        ['from' => 'idle', 'event' => 'GUARDED_START', 'to' => 'working', 'guarded' => true],
    ]))->toThrow(InvalidArgumentException::class, 'guarded rows must use [to => null]');
});

it('rejects null targets without guarded flag', function (): void {
    expect(fn () => TransitionTableMachine::assertTransitions([
        ['from' => 'idle', 'event' => 'START', 'to' => null],
    ]))->toThrow(InvalidArgumentException::class, '[to => null] requires [guarded => true]');
});

it('rejects invalid faking entries', function (): void {
    expect(fn () => TransitionTableMachine::assertTransitions([
        ['from' => 'idle', 'event' => 'START', 'to' => 'working'],
    ], faking: ['not-a-behavior']))
        ->toThrow(InvalidArgumentException::class, 'InvokableBehavior subclass FQCNs');
});

it('does not misread @always guard-fail records as blocked event transitions', function (): void {
    // ROUTE transitions idle -> routing; routing's @always guard fails (allowed=false)
    // so the machine settles in routing. The @always TRANSITION_FAIL record must
    // not make this non-guarded row read as "transition blocked by guard".
    TransitionTableMachine::assertTransitions([
        ['from' => 'idle', 'event' => 'ROUTE', 'to' => 'routing'],
    ]);
});

it('tracks rows in the path coverage tracker like startingAt tests', function (): void {
    PathCoverageTracker::reset();
    PathCoverageTracker::enable();

    // A row ending in a FINAL state completes an observed path via assertState.
    TransitionTableMachine::assertTransitions([
        ['from' => 'working', 'event' => 'FINISH', 'to' => 'completed'],
    ]);

    expect(PathCoverageTracker::observedPaths(TransitionTableMachine::class))->not->toBeEmpty();

    PathCoverageTracker::reset();
});

it('records only the from state for guarded rows in path coverage', function (): void {
    PathCoverageTracker::reset();
    PathCoverageTracker::enable();

    // Guarded rows never reach a FINAL state — no completed path is observed.
    TransitionTableMachine::assertTransitions([
        ['from' => 'idle', 'event' => 'GUARDED_START', 'to' => null, 'guarded' => true],
    ]);

    expect(PathCoverageTracker::observedPaths(TransitionTableMachine::class))->toBeEmpty();

    PathCoverageTracker::reset();
});
