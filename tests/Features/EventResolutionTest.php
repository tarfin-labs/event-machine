<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Enums\SourceType;
use Tarfinlabs\EventMachine\Definition\EventDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\CallerEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\MachineRegisteredEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\EventResolution\EventResolutionMachine;

// ============================================================
// initializeEvent() resolves through registry for EventBehavior
// ============================================================

it('returns same instance when caller sends exact registered class', function (): void {
    $definition = EventResolutionMachine::definition();
    $state      = $definition->getInitialState();

    $event = new MachineRegisteredEvent(
        type: 'TEST_EVENT',
        payload: ['amount' => 100],
    );

    $resolved = $definition->createEventBehavior($event, $state);

    // Same class — should return the original instance (optimization)
    expect($resolved)->toBe($event);
    expect($resolved)->toBeInstanceOf(MachineRegisteredEvent::class);
});

it('re-instantiates when caller sends different class with same type string', function (): void {
    $definition = EventResolutionMachine::definition();
    $state      = $definition->getInitialState();

    $callerEvent = new CallerEvent(
        type: 'TEST_EVENT',
        payload: ['amount' => 50],
    );

    $resolved = $definition->createEventBehavior($callerEvent, $state);

    expect($resolved)->toBeInstanceOf(MachineRegisteredEvent::class);
    expect($resolved)->not->toBe($callerEvent);
    expect($resolved->type)->toBe('TEST_EVENT');
    expect($resolved->payload)->toBe(['amount' => 50]);
});

it('preserves actor through re-instantiation', function (): void {
    $definition = EventResolutionMachine::definition();
    $state      = $definition->getInitialState();
    $context    = $state->context;

    $callerEvent = new CallerEvent(
        type: 'TEST_EVENT',
        payload: ['amount' => 25],
        actor: ['id' => 42, 'name' => 'Test User'],
    );

    $resolved = $definition->createEventBehavior($callerEvent, $state);

    expect($resolved)->toBeInstanceOf(MachineRegisteredEvent::class);
    expect($resolved->actor($context))->toBe(['id' => 42, 'name' => 'Test User']);
});

it('preserves isTransactional through re-instantiation', function (): void {
    $definition = EventResolutionMachine::definition();
    $state      = $definition->getInitialState();

    $callerEvent = new CallerEvent(
        type: 'TEST_EVENT',
        payload: ['amount' => 10],
        isTransactional: false,
    );

    $resolved = $definition->createEventBehavior($callerEvent, $state);

    expect($resolved)->toBeInstanceOf(MachineRegisteredEvent::class);
    expect($resolved->isTransactional)->toBeFalse();
});

it('preserves source through re-instantiation', function (): void {
    $definition = EventResolutionMachine::definition();
    $state      = $definition->getInitialState();

    $callerEvent = new CallerEvent(
        type: 'TEST_EVENT',
        payload: ['amount' => 10],
        source: SourceType::INTERNAL,
    );

    $resolved = $definition->createEventBehavior($callerEvent, $state);

    expect($resolved)->toBeInstanceOf(MachineRegisteredEvent::class);
    expect($resolved->source)->toBe(SourceType::INTERNAL);
});

it('preserves version through re-instantiation', function (): void {
    $definition = EventResolutionMachine::definition();
    $state      = $definition->getInitialState();

    $callerEvent = new CallerEvent(
        type: 'TEST_EVENT',
        payload: ['amount' => 10],
        version: 3,
    );

    $resolved = $definition->createEventBehavior($callerEvent, $state);

    expect($resolved)->toBeInstanceOf(MachineRegisteredEvent::class);
    expect($resolved->version)->toBe(3);
});

it('falls back to caller instance when type not in registry', function (): void {
    $definition = EventResolutionMachine::definition();
    $state      = $definition->getInitialState();

    $callerEvent = new EventDefinition(
        type: 'OTHER_EVENT',
        payload: ['data' => 'test'],
    );

    $resolved = $definition->createEventBehavior($callerEvent, $state);

    // Should return as-is since OTHER_EVENT is not in registry
    expect($resolved)->toBe($callerEvent);
});

it('machine validates with registered class rules when caller bypasses validation', function (): void {
    $definition = EventResolutionMachine::definition();
    $state      = $definition->getInitialState();

    // CallerEvent has no validation rules, but MachineRegisteredEvent requires amount >= 1
    $callerEvent = new CallerEvent(
        type: 'TEST_EVENT',
        payload: ['amount' => -5],
    );

    $resolved = $definition->createEventBehavior($callerEvent, $state);

    expect($resolved)->toBeInstanceOf(MachineRegisteredEvent::class);
    expect(fn () => $resolved->selfValidate())->toThrow(
        \Tarfinlabs\EventMachine\Exceptions\MachineEventValidationException::class
    );
});

it('full transition works when caller sends different class', function (): void {
    $machine = EventResolutionMachine::create();

    $callerEvent = new CallerEvent(
        type: 'TEST_EVENT',
        payload: ['amount' => 100],
    );

    $machine->send($callerEvent);

    expect($machine->state->currentStateDefinition->id)->toBe('event_resolution.processing');
});
