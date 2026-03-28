<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Routing\EndpointDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\SimpleEvent;

// === URI Generation ===

test('it generates a URI from a SCREAMING_SNAKE_CASE event type', function (): void {
    $uri = EndpointDefinition::generateUri('FARMER_SAVED');

    expect($uri)->toBe('/farmer-saved');
});

test('it generates a URI from a single-word event type', function (): void {
    $uri = EndpointDefinition::generateUri('APPROVED');

    expect($uri)->toBe('/approved');
});

test('it generates a URI from a multi-word event type', function (): void {
    $uri = EndpointDefinition::generateUri('APPROVED_WITH_INITIATIVE');

    expect($uri)->toBe('/approved-with-initiative');
});

test('it strips the _EVENT suffix when generating a URI', function (): void {
    $uri = EndpointDefinition::generateUri('CONSENT_GRANTED_EVENT');

    expect($uri)->toBe('/consent-granted');
});

// === fromConfig with string key (event type) ===

test('it creates an endpoint from null config with auto-generated URI', function (): void {
    $endpoint = EndpointDefinition::fromConfig('FARMER_SAVED', null);

    expect($endpoint->eventType)->toBe('FARMER_SAVED')
        ->and($endpoint->uri)->toBe('/farmer-saved')
        ->and($endpoint->method)->toBe('POST')
        ->and($endpoint->actionClass)->toBeNull()
        ->and($endpoint->output)->toBeNull()
        ->and($endpoint->middleware)->toBe([])
        ->and($endpoint->statusCode)->toBeNull();
});

test('it creates an endpoint from string config shorthand', function (): void {
    $endpoint = EndpointDefinition::fromConfig('FARMER_SAVED', '/farmer');

    expect($endpoint->eventType)->toBe('FARMER_SAVED')
        ->and($endpoint->uri)->toBe('/farmer')
        ->and($endpoint->method)->toBe('POST')
        ->and($endpoint->actionClass)->toBeNull()
        ->and($endpoint->output)->toBeNull()
        ->and($endpoint->middleware)->toBe([])
        ->and($endpoint->statusCode)->toBeNull();
});

test('it creates an endpoint from array config with all options', function (): void {
    $endpoint = EndpointDefinition::fromConfig('FARMER_SAVED', [
        'uri'        => '/custom-farmer',
        'method'     => 'PUT',
        'action'     => 'App\\Actions\\SaveFarmer',
        'output'     => 'farmerOutput',
        'middleware' => ['auth', 'throttle'],
        'status'     => 201,
    ]);

    expect($endpoint->eventType)->toBe('FARMER_SAVED')
        ->and($endpoint->uri)->toBe('/custom-farmer')
        ->and($endpoint->method)->toBe('PUT')
        ->and($endpoint->actionClass)->toBe('App\\Actions\\SaveFarmer')
        ->and($endpoint->output)->toBe('farmerOutput')
        ->and($endpoint->middleware)->toBe(['auth', 'throttle'])
        ->and($endpoint->statusCode)->toBe(201);
});

test('it creates an endpoint from array config with partial options using defaults', function (): void {
    $endpoint = EndpointDefinition::fromConfig('FARMER_SAVED', [
        'uri' => '/farmer',
    ]);

    expect($endpoint->eventType)->toBe('FARMER_SAVED')
        ->and($endpoint->uri)->toBe('/farmer')
        ->and($endpoint->method)->toBe('POST')
        ->and($endpoint->actionClass)->toBeNull()
        ->and($endpoint->output)->toBeNull()
        ->and($endpoint->middleware)->toBe([])
        ->and($endpoint->statusCode)->toBeNull();
});

test('it creates an endpoint from empty array config with auto-generated URI', function (): void {
    $endpoint = EndpointDefinition::fromConfig('FARMER_SAVED', []);

    expect($endpoint->eventType)->toBe('FARMER_SAVED')
        ->and($endpoint->uri)->toBe('/farmer-saved')
        ->and($endpoint->method)->toBe('POST');
});

// === fromConfig with event class key ===

test('it resolves event type from an EventBehavior class key', function (): void {
    $endpoint = EndpointDefinition::fromConfig(SimpleEvent::class, '/simple');

    expect($endpoint->eventType)->toBe('SIMPLE_EVENT')
        ->and($endpoint->uri)->toBe('/simple');
});

test('it resolves event type from an EventBehavior class key with null config', function (): void {
    $endpoint = EndpointDefinition::fromConfig(SimpleEvent::class, null);

    expect($endpoint->eventType)->toBe('SIMPLE_EVENT')
        ->and($endpoint->uri)->toBe('/simple');
});

// === List syntax (numeric key) ===

test('it creates an endpoint from list syntax with event class', function (): void {
    $endpoint = EndpointDefinition::fromConfig(SimpleEvent::class);

    expect($endpoint->eventType)->toBe('SIMPLE_EVENT')
        ->and($endpoint->uri)->toBe('/simple')
        ->and($endpoint->method)->toBe('POST')
        ->and($endpoint->actionClass)->toBeNull()
        ->and($endpoint->output)->toBeNull()
        ->and($endpoint->middleware)->toBe([])
        ->and($endpoint->statusCode)->toBeNull();
});
