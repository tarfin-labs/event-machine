<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use PHPUnit\Framework\AssertionFailedError;
use Tarfinlabs\EventMachine\Testing\RaisedEventAssertion;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\PayloadedTestEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\RaisesArrayEventAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\RaisesPayloadedEventAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\RaisesNullPayloadEventAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\RaisesInvalidPayloadedEventAction;

it('returns a fluent assertion object and keeps bare calls working', function (): void {
    RaisesPayloadedEventAction::runWithState(State::forTesting());

    $assertion = RaisesPayloadedEventAction::assertRaised(PayloadedTestEvent::class);

    expect($assertion)->toBeInstanceOf(RaisedEventAssertion::class);
});

it('asserts payload subsets with dot notation and strict comparison', function (): void {
    RaisesPayloadedEventAction::runWithState(State::forTesting());

    RaisesPayloadedEventAction::assertRaised('PAYLOADED_TEST')
        ->withPayload(['decision' => 'approved'])
        ->withPayload(['nested.level' => 1])
        ->withPayload(['nested.tags' => ['a', 'b']])
        ->withoutPayloadKey('missing')
        ->withoutPayloadKey('nested.deeper');
});

it('fails on a missing payload key naming the dot path', function (): void {
    RaisesPayloadedEventAction::runWithState(State::forTesting());

    expect(fn () => RaisesPayloadedEventAction::assertRaised(PayloadedTestEvent::class)->withPayload(['nested.absent' => 1]))
        ->toThrow(AssertionFailedError::class, 'missing payload key [nested.absent]');
});

it('fails on a strict value mismatch', function (): void {
    RaisesPayloadedEventAction::runWithState(State::forTesting());

    // '1' !== 1 — strict comparison
    expect(fn () => RaisesPayloadedEventAction::assertRaised(PayloadedTestEvent::class)->withPayload(['nested.level' => '1']))
        ->toThrow(AssertionFailedError::class, 'unexpected value for payload key [nested.level]');
});

it('rejects an empty payload subset', function (): void {
    RaisesPayloadedEventAction::runWithState(State::forTesting());

    expect(fn () => RaisesPayloadedEventAction::assertRaised(PayloadedTestEvent::class)->withPayload([]))
        ->toThrow(InvalidArgumentException::class, 'non-empty subset');
});

it('fails withoutPayloadKey when the key exists', function (): void {
    RaisesPayloadedEventAction::runWithState(State::forTesting());

    expect(fn () => RaisesPayloadedEventAction::assertRaised(PayloadedTestEvent::class)->withoutPayloadKey('nested.level'))
        ->toThrow(AssertionFailedError::class, 'unexpectedly contains payload key [nested.level]');
});

it('validated passes for a self-valid raised event', function (): void {
    RaisesPayloadedEventAction::runWithState(State::forTesting());

    RaisesPayloadedEventAction::assertRaised(PayloadedTestEvent::class)->validated();
});

it('validated surfaces the validation error message', function (): void {
    RaisesInvalidPayloadedEventAction::runWithState(State::forTesting());

    expect(fn () => RaisesInvalidPayloadedEventAction::assertRaised(PayloadedTestEvent::class)->validated())
        ->toThrow(AssertionFailedError::class, 'failed self-validation');
});

it('reads array-raised payloads and rejects validated on them', function (): void {
    RaisesArrayEventAction::runWithState(State::forTesting());

    RaisesArrayEventAction::assertRaised('ARRAY_RAISED')
        ->withPayload(['key' => 'value'])
        ->withoutPayloadKey('other');

    expect(fn () => RaisesArrayEventAction::assertRaised('ARRAY_RAISED')->validated())
        ->toThrow(AssertionFailedError::class, 'plain array');
});

it('treats null instance payloads as empty arrays', function (): void {
    RaisesNullPayloadEventAction::runWithState(State::forTesting());

    RaisesNullPayloadEventAction::assertRaised(PayloadedTestEvent::class)
        ->withoutPayloadKey('anything');

    expect(fn () => RaisesNullPayloadEventAction::assertRaised(PayloadedTestEvent::class)->withPayload(['anything' => 1]))
        ->toThrow(AssertionFailedError::class, 'missing payload key [anything]');
});
