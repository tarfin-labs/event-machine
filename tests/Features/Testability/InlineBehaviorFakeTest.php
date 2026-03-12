<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use Tarfinlabs\EventMachine\Testing\InlineBehaviorFake;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\IncrementAction;

afterEach(function (): void {
    InlineBehaviorFake::resetAll();
});

// ─── Registration: spy() ────────────────────────────────────

it('registers a spy that records calls without skipping original', function (): void {
    InlineBehaviorFake::spy('myAction');

    expect(InlineBehaviorFake::isFaked('myAction'))->toBeTrue();

    // intercept returns false for spy (don't skip original)
    $shouldSkip = InlineBehaviorFake::intercept('myAction', ['param1']);
    expect($shouldSkip)->toBeFalse();

    // Call was recorded
    expect(InlineBehaviorFake::getCalls('myAction'))->toHaveCount(1);
    expect(InlineBehaviorFake::getCalls('myAction')[0])->toBe(['param1']);
});

// ─── Registration: fake() ───────────────────────────────────

it('registers a fake that records calls and skips original', function (): void {
    InlineBehaviorFake::fake('myAction');

    expect(InlineBehaviorFake::isFaked('myAction'))->toBeTrue();

    // intercept returns true for fake (skip original)
    $shouldSkip = InlineBehaviorFake::intercept('myAction', ['param1']);
    expect($shouldSkip)->toBeTrue();

    // Call was recorded
    expect(InlineBehaviorFake::getCalls('myAction'))->toHaveCount(1);
});

it('registers a fake with custom replacement closure', function (): void {
    $called = false;
    InlineBehaviorFake::fake('myAction', function (mixed ...$args) use (&$called): null {
        $called = true;

        return null;
    });

    $shouldSkip = InlineBehaviorFake::intercept('myAction', []);
    expect($shouldSkip)->toBeTrue();

    // Execute replacement
    $replacement = InlineBehaviorFake::getReplacement('myAction');
    ($replacement)();
    expect($called)->toBeTrue();
});

// ─── Registration: shouldReturn() ───────────────────────────

it('shouldReturn registers a fake with a specific return value', function (): void {
    InlineBehaviorFake::shouldReturn('myGuard', false);

    $shouldSkip = InlineBehaviorFake::intercept('myGuard', []);
    expect($shouldSkip)->toBeTrue();

    $replacement = InlineBehaviorFake::getReplacement('myGuard');
    expect(($replacement)())->toBeFalse();
});

// ─── Registration: FQCN guard ───────────────────────────────

it('rejects class-based FQCN with InvalidArgumentException', function (): void {
    expect(fn () => InlineBehaviorFake::fake(IncrementAction::class))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects FQCN in spy() with InvalidArgumentException', function (): void {
    expect(fn () => InlineBehaviorFake::spy(IncrementAction::class))
        ->toThrow(InvalidArgumentException::class);
});

// ─── Interception ───────────────────────────────────────────

it('intercept returns false for unregistered keys', function (): void {
    expect(InlineBehaviorFake::intercept('unknown', []))->toBeFalse();
});

it('intercept returns false for spied keys (run original)', function (): void {
    InlineBehaviorFake::spy('myAction');
    expect(InlineBehaviorFake::intercept('myAction', []))->toBeFalse();
});

it('intercept returns true for faked keys (skip original)', function (): void {
    InlineBehaviorFake::fake('myAction');
    expect(InlineBehaviorFake::intercept('myAction', []))->toBeTrue();
});

it('intercept records parameters on each call', function (): void {
    InlineBehaviorFake::fake('myAction');

    InlineBehaviorFake::intercept('myAction', ['a', 'b']);
    InlineBehaviorFake::intercept('myAction', ['c']);

    $calls = InlineBehaviorFake::getCalls('myAction');
    expect($calls)->toHaveCount(2);
    expect($calls[0])->toBe(['a', 'b']);
    expect($calls[1])->toBe(['c']);
});

// ─── Assertions: assertRan ──────────────────────────────────

it('assertRan passes when behavior was called', function (): void {
    InlineBehaviorFake::fake('myAction');
    InlineBehaviorFake::intercept('myAction', []);

    // Should not throw
    InlineBehaviorFake::assertRan('myAction');
});

it('assertRan fails when behavior was not called', function (): void {
    InlineBehaviorFake::fake('myAction');

    expect(fn () => InlineBehaviorFake::assertRan('myAction'))
        ->toThrow(ExpectationFailedException::class);
});

it('assertRan fails when behavior was not registered', function (): void {
    expect(fn () => InlineBehaviorFake::assertRan('unregistered'))
        ->toThrow(ExpectationFailedException::class);
});

// ─── Assertions: assertNotRan ───────────────────────────────

it('assertNotRan passes when behavior was not called', function (): void {
    InlineBehaviorFake::fake('myAction');

    InlineBehaviorFake::assertNotRan('myAction');
});

it('assertNotRan fails when behavior was called', function (): void {
    InlineBehaviorFake::fake('myAction');
    InlineBehaviorFake::intercept('myAction', []);

    expect(fn () => InlineBehaviorFake::assertNotRan('myAction'))
        ->toThrow(ExpectationFailedException::class);
});

// ─── Assertions: assertRanTimes ─────────────────────────────

it('assertRanTimes passes with correct count', function (): void {
    InlineBehaviorFake::fake('myAction');
    InlineBehaviorFake::intercept('myAction', []);
    InlineBehaviorFake::intercept('myAction', []);

    InlineBehaviorFake::assertRanTimes('myAction', 2);
});

it('assertRanTimes fails with incorrect count', function (): void {
    InlineBehaviorFake::fake('myAction');
    InlineBehaviorFake::intercept('myAction', []);

    expect(fn () => InlineBehaviorFake::assertRanTimes('myAction', 2))
        ->toThrow(ExpectationFailedException::class);
});

// ─── Assertions: assertRanWith ──────────────────────────────

it('assertRanWith passes when callback matches (receives array, not spread)', function (): void {
    InlineBehaviorFake::fake('myAction');
    InlineBehaviorFake::intercept('myAction', ['state_obj', 'context_obj']);

    InlineBehaviorFake::assertRanWith('myAction', function (array $params): bool {
        return $params[0] === 'state_obj' && $params[1] === 'context_obj';
    });
});

it('assertRanWith fails when no call matches', function (): void {
    InlineBehaviorFake::fake('myAction');
    InlineBehaviorFake::intercept('myAction', ['wrong']);

    expect(fn () => InlineBehaviorFake::assertRanWith('myAction', fn (array $params): bool => $params[0] === 'expected'))
        ->toThrow(ExpectationFailedException::class);
});

// ─── Inspection ─────────────────────────────────────────────

it('isFaked returns true for registered keys', function (): void {
    InlineBehaviorFake::fake('myAction');
    expect(InlineBehaviorFake::isFaked('myAction'))->toBeTrue();
});

it('isFaked returns false for unregistered keys', function (): void {
    expect(InlineBehaviorFake::isFaked('unknown'))->toBeFalse();
});

it('getCalls returns empty array for unregistered keys', function (): void {
    expect(InlineBehaviorFake::getCalls('unknown'))->toBe([]);
});

it('getCalls returns recorded invocations', function (): void {
    InlineBehaviorFake::fake('myAction');
    InlineBehaviorFake::intercept('myAction', ['a']);
    InlineBehaviorFake::intercept('myAction', ['b']);

    expect(InlineBehaviorFake::getCalls('myAction'))->toHaveCount(2);
});

// ─── Cleanup ────────────────────────────────────────────────

it('reset clears a single key', function (): void {
    InlineBehaviorFake::fake('action1');
    InlineBehaviorFake::fake('action2');

    InlineBehaviorFake::reset('action1');

    expect(InlineBehaviorFake::isFaked('action1'))->toBeFalse();
    expect(InlineBehaviorFake::isFaked('action2'))->toBeTrue();
});

it('resetAll clears all keys', function (): void {
    InlineBehaviorFake::fake('action1');
    InlineBehaviorFake::fake('action2');

    InlineBehaviorFake::resetAll();

    expect(InlineBehaviorFake::isFaked('action1'))->toBeFalse();
    expect(InlineBehaviorFake::isFaked('action2'))->toBeFalse();
});

it('resetAllFakes on InvokableBehavior also clears inline fakes', function (): void {
    InlineBehaviorFake::fake('myAction');
    InlineBehaviorFake::intercept('myAction', []);

    // resetAllFakes is called via IncrementAction (any InvokableBehavior subclass)
    IncrementAction::resetAllFakes();

    expect(InlineBehaviorFake::isFaked('myAction'))->toBeFalse();
    expect(InlineBehaviorFake::getCalls('myAction'))->toBe([]);
});
