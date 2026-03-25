<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Routing\ForwardedEndpointDefinition;

// === Construction with all properties ===

test('it constructs with all properties', function (): void {
    $definition = new ForwardedEndpointDefinition(
        parentEventType: 'PARENT_FORWARD_CARD',
        childEventType: 'PROVIDE_CARD',
        childMachineClass: 'App\\Machines\\ChildMachine',
        childEventClass: 'App\\Events\\ProvideCardEvent',
        uri: '/forward/provide-card',
        method: 'PUT',
        actionClass: 'App\\Actions\\StoreCard',
        resultBehavior: 'cardResultBehavior',
        contextKeys: ['cardLast4', 'status'],
        statusCode: 201,
        middleware: ['auth', 'throttle'],
        availableEvents: true,
    );

    expect($definition->parentEventType)->toBe('PARENT_FORWARD_CARD')
        ->and($definition->childEventType)->toBe('PROVIDE_CARD')
        ->and($definition->childMachineClass)->toBe('App\\Machines\\ChildMachine')
        ->and($definition->childEventClass)->toBe('App\\Events\\ProvideCardEvent')
        ->and($definition->uri)->toBe('/forward/provide-card')
        ->and($definition->method)->toBe('PUT')
        ->and($definition->actionClass)->toBe('App\\Actions\\StoreCard')
        ->and($definition->resultBehavior)->toBe('cardResultBehavior')
        ->and($definition->contextKeys)->toBe(['cardLast4', 'status'])
        ->and($definition->statusCode)->toBe(201)
        ->and($definition->middleware)->toBe(['auth', 'throttle'])
        ->and($definition->availableEvents)->toBeTrue();
});

// === Construction with minimal properties (defaults) ===

test('it constructs with minimal properties and applies defaults', function (): void {
    $definition = new ForwardedEndpointDefinition(
        parentEventType: 'PARENT_FORWARD_CARD',
        childEventType: 'PROVIDE_CARD',
        childMachineClass: 'App\\Machines\\ChildMachine',
        childEventClass: 'App\\Events\\ProvideCardEvent',
        uri: '/forward/provide-card',
    );

    expect($definition->parentEventType)->toBe('PARENT_FORWARD_CARD')
        ->and($definition->childEventType)->toBe('PROVIDE_CARD')
        ->and($definition->childMachineClass)->toBe('App\\Machines\\ChildMachine')
        ->and($definition->childEventClass)->toBe('App\\Events\\ProvideCardEvent')
        ->and($definition->uri)->toBe('/forward/provide-card')
        ->and($definition->method)->toBe('POST')
        ->and($definition->actionClass)->toBeNull()
        ->and($definition->resultBehavior)->toBeNull()
        ->and($definition->contextKeys)->toBeNull()
        ->and($definition->statusCode)->toBeNull()
        ->and($definition->middleware)->toBe([])
        ->and($definition->availableEvents)->toBeNull();
});

// === availableEvents opt-out flag ===

test('it stores availableEvents opt-out flag', function (): void {
    $definition = new ForwardedEndpointDefinition(
        parentEventType: 'PARENT_FORWARD_CARD',
        childEventType: 'PROVIDE_CARD',
        childMachineClass: 'App\\Machines\\ChildMachine',
        childEventClass: 'App\\Events\\ProvideCardEvent',
        uri: '/forward/provide-card',
        availableEvents: false,
    );

    expect($definition->availableEvents)->toBeFalse();
});
