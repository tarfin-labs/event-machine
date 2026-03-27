<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\ComputedTestContext;

it('does not include computed values in toArray', function (): void {
    $context = new ComputedTestContext(count: 5, status: 'active');

    $array = $context->toArray();

    expect($array)->toHaveKeys(['count', 'status'])
        ->and($array)->not->toHaveKeys(['isCountEven', 'displayLabel']);
});

it('includes computed values in toResponseArray', function (): void {
    $context = new ComputedTestContext(count: 4, status: 'pending');

    $array = $context->toResponseArray();

    expect($array)
        ->toHaveKeys(['count', 'status', 'isCountEven', 'displayLabel'])
        ->and($array['count'])->toBe(4)
        ->and($array['status'])->toBe('pending')
        ->and($array['isCountEven'])->toBeTrue()
        ->and($array['displayLabel'])->toBe('Item #4 (pending)');
});

it('reflects current state in computed values', function (): void {
    $context = new ComputedTestContext(count: 0, status: 'active');

    expect($context->toResponseArray()['isCountEven'])->toBeTrue();

    $context->count = 3;

    expect($context->toResponseArray()['isCountEven'])->toBeFalse()
        ->and($context->toResponseArray()['displayLabel'])->toBe('Item #3 (active)');
});

it('returns same result for toResponseArray and toArray on base ContextManager', function (): void {
    $context = new ContextManager(data: ['a' => 1, 'b' => 'hello']);

    expect($context->toResponseArray())->toBe($context->toArray());
});
