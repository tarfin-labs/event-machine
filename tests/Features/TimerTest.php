<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Support\Timer;

it('Timer::seconds returns correct value', function (): void {
    expect(Timer::seconds(30)->inSeconds())->toBe(30);
});

it('Timer::minutes returns correct value', function (): void {
    expect(Timer::minutes(5)->inSeconds())->toBe(300);
});

it('Timer::hours returns correct value', function (): void {
    expect(Timer::hours(6)->inSeconds())->toBe(21600);
});

it('Timer::days returns correct value', function (): void {
    expect(Timer::days(7)->inSeconds())->toBe(604800);
});

it('Timer::weeks returns correct value', function (): void {
    expect(Timer::weeks(2)->inSeconds())->toBe(1209600);
});

it('Timer::minutes(1) equals Timer::seconds(60)', function (): void {
    expect(Timer::minutes(1)->inSeconds())->toBe(Timer::seconds(60)->inSeconds());
});

it('Timer::hours(1) equals Timer::minutes(60)', function (): void {
    expect(Timer::hours(1)->inSeconds())->toBe(Timer::minutes(60)->inSeconds());
});

it('Timer::days(1) equals Timer::hours(24)', function (): void {
    expect(Timer::days(1)->inSeconds())->toBe(Timer::hours(24)->inSeconds());
});

it('Timer::weeks(1) equals Timer::days(7)', function (): void {
    expect(Timer::weeks(1)->inSeconds())->toBe(Timer::days(7)->inSeconds());
});

it('Timer rejects zero duration', function (): void {
    Timer::seconds(0);
})->throws(InvalidArgumentException::class, 'must be positive');

it('Timer rejects negative duration', function (): void {
    Timer::minutes(-5);
})->throws(InvalidArgumentException::class, 'must be positive');
