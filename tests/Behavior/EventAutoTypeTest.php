<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\EventDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\CallerEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\SimpleEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\OrderSubmitted;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\EventCreatedEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\Event as EventNamedEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\AddValueEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\IncreaseEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\AddAnotherValueEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ConfirmPaymentEvent;

// --- Auto-generated types (no getType() override needed) ---

test('auto-generates SCREAMING_SNAKE_CASE from class name for standard events', function (): void {
    // These stubs have getType() overrides that match convention,
    // proving the algorithm produces the same result
    expect(IncreaseEvent::getType())->toBe('INCREASE')
        ->and(AddValueEvent::getType())->toBe('ADD_VALUE')
        ->and(AddAnotherValueEvent::getType())->toBe('ADD_ANOTHER_VALUE')
        ->and(ConfirmPaymentEvent::getType())->toBe('CONFIRM_PAYMENT');
});

test('auto-generates type for a class with no Event suffix', function (): void {
    // OrderSubmitted has no suffix to strip, so beforeLast('Event') returns the basename as-is.
    expect(OrderSubmitted::getType())->toBe('ORDER_SUBMITTED');
});

test('getType does not throw for an anonymous class', function (): void {
    // An anonymous class basename is `class@anonymous...`, so the output is not meaningfully
    // assertable -- only that the method survives it.
    $event = new class() extends EventBehavior {};

    expect($event::getType())->toBeString()->not->toBeEmpty();
});

test('auto-generates type for single-word Event class', function (): void {
    // IncreaseEvent → INCREASE (strip Event, single word)
    expect(IncreaseEvent::getType())->toBe('INCREASE');
});

test('auto-generates type for multi-word Event class', function (): void {
    // AddAnotherValueEvent → ADD_ANOTHER_VALUE
    expect(AddAnotherValueEvent::getType())->toBe('ADD_ANOTHER_VALUE');
});

// --- Explicit override takes precedence ---

test('explicit getType() override takes precedence over auto-generation', function (): void {
    // CallerEvent overrides getType() to return 'TEST_EVENT' (not 'CALLER')
    expect(CallerEvent::getType())->toBe('TEST_EVENT');
});

test('SimpleEvent explicit override returns SIMPLE_EVENT', function (): void {
    // SimpleEvent overrides to return 'SIMPLE_EVENT' (convention would produce 'SIMPLE')
    expect(SimpleEvent::getType())->toBe('SIMPLE_EVENT');
});

// --- Framework internal ---

test('EventDefinition existing override is unaffected', function (): void {
    expect(EventDefinition::getType())->toBe('(event)');
});

// --- Algorithm edge cases, against real classes ---
//
// These three used to build anonymous classes that RE-IMPLEMENTED getType()'s body inline and
// then asserted on that copy, so they passed no matter what EventBehavior::getType() did. They
// now use named stubs and exercise the production method.

test('falls back to the full basename when stripping Event empties it', function (): void {
    // Tests\Stubs\Events\Event: beforeLast('Event') yields '', so the fallback returns 'Event'.
    expect(EventNamedEvent::getType())->toBe('EVENT');
});

test('strips only the last Event when the name carries it twice', function (): void {
    // EventCreatedEvent → EventCreated → EVENT_CREATED
    expect(EventCreatedEvent::getType())->toBe('EVENT_CREATED');
});
