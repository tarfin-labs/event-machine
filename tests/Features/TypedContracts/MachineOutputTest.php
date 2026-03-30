<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Tests\Stubs\Outputs\PaymentOutput;
use Tarfinlabs\EventMachine\Exceptions\MachineOutputResolutionException;

test('fromContext resolves all params from context', function (): void {
    $ctx = new ContextManager(['paymentId' => 'pay_1', 'status' => 'success', 'transactionRef' => 'ref_1']);

    $output = PaymentOutput::fromContext($ctx);

    expect($output->paymentId)->toBe('pay_1')
        ->and($output->status)->toBe('success')
        ->and($output->transactionRef)->toBe('ref_1');
});

test('fromContext throws MachineOutputResolutionException when required param missing', function (): void {
    $ctx = new ContextManager(['paymentId' => 'pay_1']);

    PaymentOutput::fromContext($ctx);
})->throws(MachineOutputResolutionException::class, "missing required field 'status'");

test('toArray serializes for HTTP response envelope', function (): void {
    $output = new PaymentOutput(paymentId: 'pay_2', status: 'completed', transactionRef: 'ref_2');

    expect($output->toArray())->toBe([
        'paymentId'      => 'pay_2',
        'status'         => 'completed',
        'transactionRef' => 'ref_2',
    ]);
});

test('toArray serializes nullable as null', function (): void {
    $output = new PaymentOutput(paymentId: 'pay_3', status: 'pending');

    expect($output->toArray()['transactionRef'])->toBeNull();
});
