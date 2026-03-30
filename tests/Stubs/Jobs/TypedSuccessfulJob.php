<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Jobs;

use Tarfinlabs\EventMachine\Behavior\MachineFailure;
use Tarfinlabs\EventMachine\Contracts\ReturnsOutput;
use Tarfinlabs\EventMachine\Contracts\ProvidesFailure;
use Tarfinlabs\EventMachine\Tests\Stubs\Outputs\PaymentOutput;
use Tarfinlabs\EventMachine\Tests\Stubs\Failures\PaymentFailure;

class TypedSuccessfulJob implements ProvidesFailure, ReturnsOutput
{
    public function __construct(
        private readonly string $orderId = '',
    ) {}

    public function handle(): void
    {
        // Simulates successful work
    }

    public function output(): PaymentOutput
    {
        return new PaymentOutput(
            paymentId: 'pay_typed_123',
            status: 'success',
            transactionRef: 'ref_abc',
        );
    }

    public static function failure(\Throwable $e): MachineFailure
    {
        return PaymentFailure::fromException($e);
    }
}
