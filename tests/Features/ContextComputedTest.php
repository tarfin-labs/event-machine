<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\ComputedTestContext;

it('does not include computed values in toArray', function (): void {
    $context = new ComputedTestContext(count: 5, status: 'active');

    $array = $context->toArray();

    expect($array)->toHaveKeys(['count', 'status'])
        ->and($array)->not->toHaveKeys(['is_count_even', 'display_label']);
});

it('includes computed values in toResponseArray', function (): void {
    $context = new ComputedTestContext(count: 4, status: 'pending');

    $array = $context->toResponseArray();

    expect($array)
        ->toHaveKeys(['count', 'status', 'is_count_even', 'display_label'])
        ->and($array['count'])->toBe(4)
        ->and($array['status'])->toBe('pending')
        ->and($array['is_count_even'])->toBeTrue()
        ->and($array['display_label'])->toBe('Item #4 (pending)');
});

it('reflects current state in computed values', function (): void {
    $context = new ComputedTestContext(count: 0, status: 'active');

    expect($context->toResponseArray()['is_count_even'])->toBeTrue();

    $context->count = 3;

    expect($context->toResponseArray()['is_count_even'])->toBeFalse()
        ->and($context->toResponseArray()['display_label'])->toBe('Item #3 (active)');
});

it('returns same result for toResponseArray and toArray on base ContextManager', function (): void {
    $context = GenericContext::from(['a' => 1, 'b' => 'hello']);

    expect($context->toResponseArray())->toBe($context->toArray());
});
