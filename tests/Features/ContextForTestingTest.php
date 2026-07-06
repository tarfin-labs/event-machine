<?php

declare(strict_types=1);

use Spatie\LaravelData\Optional;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\ForTestingTypedContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\ForTestingRequiredContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\ForTestingGrandchildContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\ForTestingBareOptionalContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\ForTestingNoConstructorContext;

it('auto-fills union Optional parameters without defaults', function (): void {
    $context = ForTestingTypedContext::forTesting();

    expect($context->name)->toBeInstanceOf(Optional::class)
        ->and($context->count)->toBeInstanceOf(Optional::class)
        ->and($context->note)->toBeNull()
        ->and($context->limit)->toBe(5);
});

it('auto-fills bare Optional-typed parameters', function (): void {
    $context = ForTestingBareOptionalContext::forTesting();

    expect($context->pure)->toBeInstanceOf(Optional::class);
});

it('overrides win over defaults and auto-fill', function (): void {
    $context = ForTestingTypedContext::forTesting([
        'name'  => 'tarfin',
        'note'  => 'overridden',
        'limit' => 9,
    ]);

    expect($context->name)->toBe('tarfin')
        ->and($context->note)->toBe('overridden')
        ->and($context->limit)->toBe(9)
        ->and($context->count)->toBeInstanceOf(Optional::class);
});

it('keeps native defaults for Optional-typed parameters with defaults', function (): void {
    // limit is Optional|int with a native default of 5 — the default wins over auto-Optional fill
    $context = ForTestingTypedContext::forTesting();

    expect($context->limit)->toBe(5);
});

it('base ContextManager passthrough builds the data array', function (): void {
    $context = ContextManager::forTesting(['count' => 1]);

    expect($context)->toBeInstanceOf(ContextManager::class)
        ->and($context->get('count'))->toBe(1)
        ->and($context->machineId())->toBe('test-machine-id');
});

it('grandchild inheriting a typed constructor uses the typed path', function (): void {
    $context = ForTestingGrandchildContext::forTesting(['name' => 'nested']);

    expect($context)->toBeInstanceOf(ForTestingGrandchildContext::class)
        ->and($context->name)->toBe('nested')
        ->and($context->count)->toBeInstanceOf(Optional::class);
});

it('rejects subclasses without a typed constructor', function (): void {
    expect(fn () => ForTestingNoConstructorContext::forTesting())
        ->toThrow(InvalidArgumentException::class, 'does not declare a typed constructor');
});

it('rejects unknown override keys listing valid parameter names', function (): void {
    expect(fn () => ForTestingTypedContext::forTesting(['nmae' => 'typo']))
        ->toThrow(InvalidArgumentException::class, 'Unknown override key [nmae]');

    try {
        ForTestingTypedContext::forTesting(['nmae' => 'typo']);
    } catch (InvalidArgumentException $exception) {
        expect($exception->getMessage())->toContain('name', 'count', 'note', 'limit');
    }
});

it('sets machine identity by default and skips it when null', function (): void {
    $withIdentity = ForTestingTypedContext::forTesting();
    expect($withIdentity->machineId())->toBe('test-machine-id');

    $customIdentity = ForTestingTypedContext::forTesting(machineId: 'custom-id');
    expect($customIdentity->machineId())->toBe('custom-id');

    $withoutIdentity = ForTestingTypedContext::forTesting(machineId: null);
    expect($withoutIdentity->machineId())->toBeNull();
});

it('lets required non-Optional parameters fail construction naturally', function (): void {
    expect(fn () => ForTestingRequiredContext::forTesting())
        ->toThrow(ArgumentCountError::class);
});
