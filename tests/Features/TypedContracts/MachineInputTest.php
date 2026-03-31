<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\MachineInput;
use Tarfinlabs\EventMachine\Tests\Stubs\Inputs\PaymentInput;
use Tarfinlabs\EventMachine\Exceptions\MachineInputValidationException;

// ═══════════════════════════════════════════════════════════════
//  MachineInput::fromContext() — Resolution
// ═══════════════════════════════════════════════════════════════

test('fromContext resolves all params from context', function (): void {
    $ctx = new ContextManager(['orderId' => 'ORD-1', 'amount' => 150, 'currency' => 'USD']);

    $input = PaymentInput::fromContext($ctx);

    expect($input->orderId)->toBe('ORD-1')
        ->and($input->amount)->toBe(150)
        ->and($input->currency)->toBe('USD');
});

test('fromContext uses default value when context key missing but default exists', function (): void {
    $ctx = new ContextManager(['orderId' => 'ORD-2', 'amount' => 200]);

    $input = PaymentInput::fromContext($ctx);

    expect($input->currency)->toBe('TRY');
});

test('fromContext uses null when context key missing but param is nullable', function (): void {
    $inputClass = new class('test', 100, null) extends MachineInput {
        public function __construct(
            public readonly string $orderId,
            public readonly int $amount,
            public readonly ?string $note = null,
        ) {}
    };

    $ctx   = new ContextManager(['orderId' => 'ORD-3', 'amount' => 300]);
    $input = $inputClass::fromContext($ctx);

    expect($input->note)->toBeNull();
});

test('fromContext throws MachineInputValidationException when required param missing', function (): void {
    $ctx = new ContextManager(['orderId' => 'ORD-1']);

    PaymentInput::fromContext($ctx);
})->throws(MachineInputValidationException::class, "missing required field 'amount'");

test('fromContext exception message lists available context keys', function (): void {
    $ctx = new ContextManager(['orderId' => 'ORD-1', 'name' => 'Ali']);

    try {
        PaymentInput::fromContext($ctx);
        $this->fail('Expected exception');
    } catch (MachineInputValidationException $e) {
        expect($e->getMessage())->toContain('orderId')
            ->and($e->getMessage())->toContain('name');
    }
});

test('fromContext handles zero and empty string as valid values', function (): void {
    $ctx = new ContextManager(['orderId' => '', 'amount' => 0, 'currency' => 'TRY']);

    $input = PaymentInput::fromContext($ctx);

    expect($input->orderId)->toBe('')
        ->and($input->amount)->toBe(0);
});

test('fromContext ignores extra context keys not in constructor', function (): void {
    $ctx = new ContextManager(['orderId' => 'ORD-1', 'amount' => 100, 'extraKey' => 'ignored']);

    $input = PaymentInput::fromContext($ctx);

    expect($input->orderId)->toBe('ORD-1')
        ->and($input->amount)->toBe(100);
});

// ═══════════════════════════════════════════════════════════════
//  MachineInput::toArray() — Serialization
// ═══════════════════════════════════════════════════════════════

test('toArray serializes all properties with camelCase keys', function (): void {
    $input = new PaymentInput(orderId: 'ORD-1', amount: 150, currency: 'USD');

    expect($input->toArray())->toBe([
        'orderId'  => 'ORD-1',
        'amount'   => 150,
        'currency' => 'USD',
    ]);
});

test('toArray includes properties with default values', function (): void {
    $input = new PaymentInput(orderId: 'ORD-2', amount: 200);

    expect($input->toArray())->toBe([
        'orderId'  => 'ORD-2',
        'amount'   => 200,
        'currency' => 'TRY',
    ]);
});

test('toArray roundtrips through fromContext', function (): void {
    $original = new PaymentInput(orderId: 'ORD-5', amount: 500, currency: 'EUR');
    $ctx      = new ContextManager($original->toArray());
    $restored = PaymentInput::fromContext($ctx);

    expect($restored->toArray())->toBe($original->toArray());
});
