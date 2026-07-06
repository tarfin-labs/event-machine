<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Tests\Stubs\Guards\RawContextCountGuard;

it('accepts a raw context array', function (): void {
    expect(RawContextCountGuard::runWithState(['count' => 1]))->toBeTrue()
        ->and(RawContextCountGuard::runWithState(['count' => 0]))->toBeFalse();
});

it('accepts a ContextManager instance', function (): void {
    expect(RawContextCountGuard::runWithState(new ContextManager(['count' => 3])))->toBeTrue();
});

it('accepts a pre-built State unchanged', function (): void {
    $state = State::forTesting(['count' => 2]);

    expect(RawContextCountGuard::runWithState($state))->toBeTrue();
});

it('produces identical injection behavior across all three input forms', function (): void {
    $fromArray   = RawContextCountGuard::runWithState(['count' => 7]);
    $fromContext = RawContextCountGuard::runWithState(new ContextManager(['count' => 7]));
    $fromState   = RawContextCountGuard::runWithState(State::forTesting(['count' => 7]));

    expect($fromArray)->toBe($fromContext)->toBe($fromState)->toBeTrue();
});

it('keeps the named argument state for backward compatibility', function (): void {
    expect(RawContextCountGuard::runWithState(state: ['count' => 1]))->toBeTrue();
});
