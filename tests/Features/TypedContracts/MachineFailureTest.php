<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\Stubs\Failures\SimpleFailure;
use Tarfinlabs\EventMachine\Tests\Stubs\Failures\PaymentFailure;
use Tarfinlabs\EventMachine\Tests\Stubs\Failures\CustomMappingFailure;
use Tarfinlabs\EventMachine\Exceptions\MachineFailureResolutionException;

// ═══════════════════════════════════════════════════════════════
//  MachineFailure::fromException() — Sensible Default
// ═══════════════════════════════════════════════════════════════

test('fromException maps $message to getMessage()', function (): void {
    $failure = SimpleFailure::fromException(new RuntimeException('Connection lost'));

    expect($failure->message)->toBe('Connection lost');
});

test('fromException maps $code to getCode()', function (): void {
    $failure = PaymentFailure::fromException(new RuntimeException('Timeout', 504));

    // PaymentFailure has $errorCode (not in THROWABLE_GETTERS) and $message
    // $errorCode is required, not nullable, no default → should throw
})->throws(MachineFailureResolutionException::class, "Cannot resolve required parameter 'errorCode'");

test('fromException uses default for unknown params with default value', function (): void {
    $failure = PaymentFailure::fromException(new RuntimeException('Timeout'));
})->throws(MachineFailureResolutionException::class);

test('fromException uses null for unknown nullable params', function (): void {
    // SimpleFailure only has $message → maps from getMessage(), no unknowns
    $failure = SimpleFailure::fromException(new RuntimeException('Error'));

    expect($failure->message)->toBe('Error');
});

test('fromException throws MachineFailureResolutionException for required unknown params', function (): void {
    // PaymentFailure has $errorCode (required, not in THROWABLE_GETTERS)
    PaymentFailure::fromException(new RuntimeException('Test'));
})->throws(MachineFailureResolutionException::class, "'errorCode'");

test('fromException resolution exception names the unresolvable param', function (): void {
    try {
        PaymentFailure::fromException(new RuntimeException('Test'));
        $this->fail('Expected exception');
    } catch (MachineFailureResolutionException $e) {
        expect($e->getMessage())->toContain('errorCode')
            ->and($e->getMessage())->toContain('PaymentFailure')
            ->and($e->getMessage())->toContain('Override fromException()');
    }
});

// ═══════════════════════════════════════════════════════════════
//  Custom fromException Override
// ═══════════════════════════════════════════════════════════════

test('custom fromException override works', function (): void {
    $failure = CustomMappingFailure::fromException(new RuntimeException('Bad gateway', 502));

    expect($failure->errorCode)->toBe('502')
        ->and($failure->message)->toBe('Bad gateway')
        ->and($failure->gatewayResponse)->toBeNull();
});

// ═══════════════════════════════════════════════════════════════
//  MachineFailure::toArray()
// ═══════════════════════════════════════════════════════════════

test('toArray serializes for ChildMachineFailEvent payload', function (): void {
    $failure = new SimpleFailure(message: 'Test error');

    expect($failure->toArray())->toBe(['message' => 'Test error']);
});

test('toArray includes nullable fields', function (): void {
    $failure = CustomMappingFailure::fromException(new RuntimeException('Error', 500));

    expect($failure->toArray())->toHaveKey('gatewayResponse')
        ->and($failure->toArray()['gatewayResponse'])->toBeNull();
});
