<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\ScheduleDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\SimpleEvent;

it('fromConfig creates definition with class-based resolver', function (): void {
    $def = ScheduleDefinition::fromConfig('CHECK_EXPIRY', 'App\\Resolvers\\ExpiredResolver');

    expect($def->eventType)->toBe('CHECK_EXPIRY')
        ->and($def->resolver)->toBe('App\\Resolvers\\ExpiredResolver')
        ->and($def->hasResolver())->toBeTrue();
});

it('fromConfig creates definition with closure resolver', function (): void {
    $closure = fn () => collect(['id-1', 'id-2']);

    $def = ScheduleDefinition::fromConfig('SEND_REMINDER', $closure);

    expect($def->eventType)->toBe('SEND_REMINDER')
        ->and($def->resolver)->toBe($closure)
        ->and($def->hasResolver())->toBeTrue();
});

it('fromConfig creates definition with null resolver for auto-detect', function (): void {
    $def = ScheduleDefinition::fromConfig('DAILY_REPORT', null);

    expect($def->eventType)->toBe('DAILY_REPORT')
        ->and($def->resolver)->toBeNull()
        ->and($def->hasResolver())->toBeFalse();
});

it('fromConfig resolves EventBehavior FQCN key to event type string', function (): void {
    $def = ScheduleDefinition::fromConfig(SimpleEvent::class, 'App\\Resolvers\\SimpleResolver');

    expect($def->eventType)->toBe('SIMPLE_EVENT')
        ->and($def->resolver)->toBe('App\\Resolvers\\SimpleResolver');
});

it('hasResolver returns false for null', function (): void {
    $def = new ScheduleDefinition(eventType: 'TEST', resolver: null);

    expect($def->hasResolver())->toBeFalse();
});

it('hasResolver returns true for class string', function (): void {
    $def = new ScheduleDefinition(eventType: 'TEST', resolver: 'SomeResolver');

    expect($def->hasResolver())->toBeTrue();
});

it('hasResolver returns true for closure', function (): void {
    $def = new ScheduleDefinition(eventType: 'TEST', resolver: fn () => collect());

    expect($def->hasResolver())->toBeTrue();
});
