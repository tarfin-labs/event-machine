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

it('can remove a key from context data', function () {
    $context = new ContextDefinition();

    $context->set('key1', 'value1');
    $context->set('key2', 'value2');
    $context->remove('key1');

    expect($context->has('key1'))->toBeFalse();
    expect($context->has('key2'))->toBeTrue();
});

it('can initialize context data with an array', function () {
    $initialData = ['key1' => 'value1', 'key2' => 'value2'];
    $context = new ContextDefinition($initialData);

    expect($context->get('key1'))->toBe('value1');
    expect($context->get('key2'))->toBe('value2');
});

it('can convert context data to an array', function () {
    $initialData = ['key1' => 'value1', 'key2' => 'value2'];
    $context = new ContextDefinition($initialData);

    $contextArray = $context->toArray();

    expect($contextArray)->toBeArray();
    expect($contextArray)->toHaveCount(2);
    expect($contextArray['key1'])->toBe('value1');
    expect($contextArray['key2'])->toBe('value2');
});

it('can handle edge cases with empty keys and values', function () {
    $context = new ContextDefinition();

    $context->set('', 'empty_key_value');
    $context->set('empty_value_key', '');

    expect($context->get(''))->toBe('empty_key_value');
    expect($context->get('empty_value_key'))->toBe('');

    expect($context->has(''))->toBeTrue();
    expect($context->has('empty_value_key'))->toBeTrue();

    $context->remove('');
    expect($context->has(''))->toBeFalse();
    expect($context->has('empty_value_key'))->toBeTrue();

    $contextArray = $context->toArray();
    expect($contextArray)->toHaveCount(1);
    expect($contextArray['empty_value_key'])->toBe('');
});
