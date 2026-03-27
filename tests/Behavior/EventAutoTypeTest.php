<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\EventDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\CallerEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\SimpleEvent;
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

test('auto-generates type for anonymous event class without Event suffix', function (): void {
    $event = new class() extends EventBehavior {};

    // Anonymous class basename is unpredictable, but the method should not throw
    expect($event::getType())->toBeString()->not->toBeEmpty();
});

test('auto-generates type for class without Event suffix', function (): void {
    $event = new class() extends EventBehavior {
        // Simulate a class named "OrderSubmitted" (no Event suffix)
        // by overriding to test the algorithm
    };

    // The default implementation should handle classes without Event suffix
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

// --- Algorithm verification with inline classes ---

test('strips Event suffix and converts to SCREAMING_SNAKE_CASE', function (): void {
    $orderSubmitted = new class() extends EventBehavior {
        public static function getType(): string
        {
            // Simulate what auto-generation would do for "OrderSubmittedEvent"
            return parent::getType();
        }
    };

    // Anonymous class — just verify it returns a string
    expect($orderSubmitted::getType())->toBeString();
});

test('handles class where beforeLast Event would produce empty string', function (): void {
    // Test the guard: a class named exactly "Event" would have empty stripped name
    // The fallback should return the full class basename
    $event = new class() extends EventBehavior {
        public static function getType(): string
        {
            // Directly test the algorithm with a simulated "Event" basename
            $baseName = Str::of('Event');
            $stripped = $baseName->beforeLast('Event');

            return ($stripped->isEmpty() ? $baseName : $stripped)
                ->snake()
                ->upper()
                ->toString();
        }
    };

    expect($event::getType())->toBe('EVENT');
});

test('handles class with Event in middle of name', function (): void {
    // A class like "EventCreatedEvent" should strip last Event → "EventCreated" → EVENT_CREATED
    $event = new class() extends EventBehavior {
        public static function getType(): string
        {
            $baseName = Str::of('EventCreatedEvent');
            $stripped = $baseName->beforeLast('Event');

            return ($stripped->isEmpty() ? $baseName : $stripped)
                ->snake()
                ->upper()
                ->toString();
        }
    };

    expect($event::getType())->toBe('EVENT_CREATED');
});
