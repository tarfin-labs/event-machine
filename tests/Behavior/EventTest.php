<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\Stubs\Events\SimpleEvent;

test('an event has a version', function (): void {
    $eventWithoutExplicitVersionDefinition = new SimpleEvent();
    $eventWithVersionDefinition            = new SimpleEvent(version: 13);

    expect($eventWithoutExplicitVersionDefinition->version)->toBe(1)
        ->and($eventWithVersionDefinition->version)->toBe(13);
});

test('an event has a type', function (): void {
    $event = new SimpleEvent();

    expect($event->type)->toBe('SIMPLE_EVENT')
        ->and(SimpleEvent::getType())->toBe('SIMPLE_EVENT');
});

test('all method returns entire payload', function (): void {
    $payload = [
        'name'  => 'John',
        'email' => 'john@example.com',
        'age'   => 30,
    ];
    $event = new SimpleEvent(payload: $payload);

    expect($event->all())->toBe($payload);
});

test('all method returns empty array when payload is null', function (): void {
    $event = new SimpleEvent();

    expect($event->all())->toBe([]);
});

test('all method returns specific keys from payload', function (): void {
    $payload = [
        'name'  => 'John',
        'email' => 'john@example.com',
        'age'   => 30,
        'city'  => 'New York',
    ];
    $event  = new SimpleEvent(payload: $payload);
    $result = $event->all(['name', 'email']);

    expect($result)->toBe([
        'name'  => 'John',
        'email' => 'john@example.com',
    ]);
});

test('all method handles nested keys', function (): void {
    $payload = [
        'user' => [
            'name'  => 'John',
            'email' => 'john@example.com',
        ],
        'settings' => [
            'theme' => 'dark',
        ],
    ];
    $event  = new SimpleEvent(payload: $payload);
    $result = $event->all(['user.name', 'settings.theme']);

    expect($result)->toBe([
        'user'     => ['name' => 'John'],
        'settings' => ['theme' => 'dark'],
    ]);
});

test('all method returns null for non-existent keys', function (): void {
    $payload = [
        'name' => 'John',
    ];
    $event  = new SimpleEvent(payload: $payload);
    $result = $event->all(['name', 'nonexistent']);

    expect($result)->toBe([
        'name'        => 'John',
        'nonexistent' => null,
    ]);
});

test('data method retrieves value by key', function (): void {
    $payload = [
        'name'  => 'John',
        'email' => 'john@example.com',
    ];
    $event = new SimpleEvent(payload: $payload);

    expect($event->data('name'))->toBe('John')
        ->and($event->data('email'))->toBe('john@example.com');
});

test('data method retrieves nested value with dot notation', function (): void {
    $payload = [
        'user' => [
            'name'    => 'John',
            'address' => [
                'city' => 'New York',
                'zip'  => '10001',
            ],
        ],
    ];
    $event = new SimpleEvent(payload: $payload);

    expect($event->data('user.name'))->toBe('John')
        ->and($event->data('user.address.city'))->toBe('New York')
        ->and($event->data('user.address.zip'))->toBe('10001');
});

test('data method returns default value for non-existent key', function (): void {
    $payload = [
        'name' => 'John',
    ];
    $event = new SimpleEvent(payload: $payload);

    expect($event->data('email', 'default@example.com'))->toBe('default@example.com')
        ->and($event->data('nonexistent', 'default'))->toBe('default');
});

test('data method returns null for non-existent key without default', function (): void {
    $payload = [
        'name' => 'John',
    ];
    $event = new SimpleEvent(payload: $payload);

    expect($event->data('nonexistent'))->toBeNull();
});

test('data method returns all data when key is null', function (): void {
    $payload = [
        'name'  => 'John',
        'email' => 'john@example.com',
    ];
    $event = new SimpleEvent(payload: $payload);

    expect($event->data())->toBe($payload);
});

test('trait methods work with event payload', function (): void {
    $event = new SimpleEvent(payload: [
        'name'      => 'John Doe',
        'age'       => '30',
        'price'     => '19.99',
        'active'    => true,
        'birthdate' => '1994-01-01',
        'tags'      => ['php', 'laravel'],
    ]);

    expect($event->has('name'))->toBeTrue()
        ->and($event->missing('email'))->toBeTrue()
        ->and($event->integer('age'))->toBe(30)
        ->and($event->float('price'))->toBe(19.99)
        ->and($event->boolean('active'))->toBeTrue()
        ->and($event->str('name'))->toEqual(str('John Doe'))
        ->and($event->string('name')->upper()->toString())->toBe('JOHN DOE')
        ->and($event->date('birthdate'))->toEqual(Date::parse('1994-01-01'))
        ->and($event->array('tags'))->toEqual(['php', 'laravel'])
        ->and($event->collection('tags'))->toHaveCount(2);
});
