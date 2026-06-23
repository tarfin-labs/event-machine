<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Routing\ReadDefinition;
use Tarfinlabs\EventMachine\Exceptions\InvalidRouterConfigException;

// === Value forms ===

test('null value uses all defaults with uri from the key', function (): void {
    $read = ReadDefinition::fromConfig('status', null);

    expect($read->name)->toBe('status')
        ->and($read->uri)->toBe('/status')
        ->and($read->output)->toBeNull()
        ->and($read->middleware)->toBe([])
        ->and($read->statusCode)->toBe(200)
        ->and($read->availableEvents)->toBeNull();
});

test('string value is a URI shorthand', function (): void {
    $read = ReadDefinition::fromConfig('status', '/state');

    expect($read->name)->toBe('status')
        ->and($read->uri)->toBe('/state');
});

test('options array sets every recognized option', function (): void {
    $read = ReadDefinition::fromConfig('resume', [
        'uri'              => 'resume',
        'output'           => ['orderId'],
        'middleware'       => ['auth:retailer'],
        'status'           => 200,
        'available_events' => false,
    ]);

    expect($read->uri)->toBe('/resume')
        ->and($read->output)->toBe(['orderId'])
        ->and($read->middleware)->toBe(['auth:retailer'])
        ->and($read->statusCode)->toBe(200)
        ->and($read->availableEvents)->toBeFalse();
});

// === Rejections ===

test('it rejects the action key', function (): void {
    ReadDefinition::fromConfig('status', ['action' => 'SomeAction']);
})->throws(InvalidRouterConfigException::class);

test('it rejects the method key', function (): void {
    ReadDefinition::fromConfig('status', ['method' => 'POST']);
})->throws(InvalidRouterConfigException::class);

test('it rejects an unrecognized key', function (): void {
    ReadDefinition::fromConfig('status', ['midleware' => []]);
})->throws(InvalidRouterConfigException::class);

test('it rejects an empty URI', function (): void {
    ReadDefinition::fromConfig('status', ['uri' => '']);
})->throws(InvalidRouterConfigException::class);

test('it rejects a slash-only URI', function (): void {
    ReadDefinition::fromConfig('status', ['uri' => '/']);
})->throws(InvalidRouterConfigException::class);

test('it rejects a URI containing a route placeholder', function (): void {
    ReadDefinition::fromConfig('status', ['uri' => 'foo/{bar}']);
})->throws(InvalidRouterConfigException::class);

// === URI normalization ===

test('normalizeUri strips surrounding slashes and prepends one', function (): void {
    expect(ReadDefinition::normalizeUri('status/'))->toBe('/status')
        ->and(ReadDefinition::normalizeUri('/status'))->toBe('/status')
        ->and(ReadDefinition::normalizeUri('status'))->toBe('/status');
});

test('multi-segment URIs are allowed', function (): void {
    expect(ReadDefinition::normalizeUri('status/full'))->toBe('/status/full');
});
