<?php

declare(strict_types=1);

use Spatie\LaravelData\Optional;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\AddValueEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\IncreaseEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\AddValueAction;

// ─── Basic factory usage ─────────────────────────────────────

it('creates event with defaults via forTesting()', function (): void {
    $event = IncreaseEvent::forTesting();

    expect($event)->toBeInstanceOf(EventBehavior::class);
    expect($event->type)->toBe('INCREASE');
    expect($event->payload)->toBe([]);
    expect($event->version)->toBe(1);
});

it('creates event with custom payload', function (): void {
    $event = AddValueEvent::forTesting([
        'payload' => ['value' => 42],
    ]);

    expect($event->type)->toBe('ADD_VALUE');
    expect($event->payload)->toBe(['value' => 42]);
});

it('overrides defaults with provided attributes', function (): void {
    $event = IncreaseEvent::forTesting([
        'version' => 2,
        'payload' => ['custom' => true],
    ]);

    expect($event->type)->toBe('INCREASE');
    expect($event->version)->toBe(2);
    expect($event->payload)->toBe(['custom' => true]);
});

it('overrides type when explicitly provided', function (): void {
    $event = IncreaseEvent::forTesting([
        'type' => 'CUSTOM_TYPE',
    ]);

    expect($event->type)->toBe('CUSTOM_TYPE');
});

// ─── Returns correct static type ─────────────────────────────

it('returns an instance of the concrete event class', function (): void {
    $event = IncreaseEvent::forTesting();
    expect($event)->toBeInstanceOf(IncreaseEvent::class);

    $event = AddValueEvent::forTesting();
    expect($event)->toBeInstanceOf(AddValueEvent::class);
});

// ─── Integration with runWithState ───────────────────────────

it('can be used with runWithState', function (): void {
    $state = State::forTesting(
        new TrafficLightsContext(
            count: 10,
            modelA: new Optional(),
        )
    );

    $event = AddValueEvent::forTesting(['payload' => ['value' => 5]]);

    AddValueAction::runWithState($state, eventBehavior: $event);

    expect($state->context->count)->toBe(15);
});
