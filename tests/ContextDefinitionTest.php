<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextDefinition;

it('can set and get context definition data', function () {
    $context = new ContextDefinition();

    $context->set('key1', 'value1');
    $context->set('key2', 'value2');

    expect($context->get('key1'))->toBe('value1');
    expect($context->get('key2'))->toBe('value2');
});

it('returns null for non-existent keys', function () {
    $context = new ContextDefinition();

    expect($context->get('non_existent_key'))->toBeNull();
});

it('can check if a key exists', function () {
    $context = new ContextDefinition();
    $context->set('key1', 'value1');

    expect($context->has('key1'))->toBeTrue();
    expect($context->has('non_existent_key'))->toBeFalse();
});
