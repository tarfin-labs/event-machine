<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Behavior\MachineOutput;
use Tarfinlabs\EventMachine\Contracts\ReturnsOutput;
use Tarfinlabs\EventMachine\Contracts\ProvidesFailure;
use Tarfinlabs\EventMachine\Tests\Stubs\Jobs\NoInterfaceJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Jobs\TypedFailingJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Jobs\UntypedOutputJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Outputs\PaymentOutput;
use Tarfinlabs\EventMachine\Tests\Stubs\Jobs\TypedSuccessfulJob;
use Tarfinlabs\EventMachine\Exceptions\MachineFailureResolutionException;

// ═══════════════════════════════════════════════════════════════
//  ReturnsOutput Interface
// ═══════════════════════════════════════════════════════════════

test('ReturnsOutput interface exists and defines output() method', function (): void {
    $reflection = new ReflectionClass(ReturnsOutput::class);

    expect($reflection->isInterface())->toBeTrue()
        ->and($reflection->hasMethod('output'))->toBeTrue();

    $method = $reflection->getMethod('output');
    expect($method->isPublic())->toBeTrue();
});

test('ProvidesFailure interface exists and defines failure() method', function (): void {
    $reflection = new ReflectionClass(ProvidesFailure::class);

    expect($reflection->isInterface())->toBeTrue()
        ->and($reflection->hasMethod('failure'))->toBeTrue();

    $method = $reflection->getMethod('failure');
    expect($method->isPublic())->toBeTrue()
        ->and($method->isStatic())->toBeTrue();
});

// ═══════════════════════════════════════════════════════════════
//  TypedSuccessfulJob — ReturnsOutput
// ═══════════════════════════════════════════════════════════════

test('TypedSuccessfulJob implements ReturnsOutput', function (): void {
    $job = new TypedSuccessfulJob();

    expect($job)->toBeInstanceOf(ReturnsOutput::class);
});

test('TypedSuccessfulJob output() returns MachineOutput', function (): void {
    $job    = new TypedSuccessfulJob();
    $output = $job->output();

    expect($output)->toBeInstanceOf(MachineOutput::class)
        ->and($output)->toBeInstanceOf(PaymentOutput::class)
        ->and($output->paymentId)->toBe('pay_typed_123')
        ->and($output->status)->toBe('success')
        ->and($output->transactionRef)->toBe('ref_abc');
});

// ═══════════════════════════════════════════════════════════════
//  UntypedOutputJob — array output backward compat
// ═══════════════════════════════════════════════════════════════

test('UntypedOutputJob output() returns array', function (): void {
    $job    = new UntypedOutputJob();
    $output = $job->output();

    expect($output)->toBeArray()
        ->and($output)->toBe(['done' => true, 'message' => 'completed']);
});

// ═══════════════════════════════════════════════════════════════
//  NoInterfaceJob — no contract
// ═══════════════════════════════════════════════════════════════

test('NoInterfaceJob does not implement ReturnsOutput', function (): void {
    $job = new NoInterfaceJob();

    expect($job)->not->toBeInstanceOf(ReturnsOutput::class);
});

// ═══════════════════════════════════════════════════════════════
//  TypedFailingJob — ProvidesFailure
// ═══════════════════════════════════════════════════════════════

test('TypedFailingJob implements ProvidesFailure', function (): void {
    $job = new TypedFailingJob();

    expect($job)->toBeInstanceOf(ProvidesFailure::class);
});

test('TypedFailingJob failure() delegates to PaymentFailure::fromException', function (): void {
    // PaymentFailure has required $errorCode which cannot be auto-resolved
    // from a plain RuntimeException, so fromException throws
    TypedFailingJob::failure(new RuntimeException('Gateway timeout', 503));
})->throws(MachineFailureResolutionException::class);
