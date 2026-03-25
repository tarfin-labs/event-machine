<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Jobs;

use Tarfinlabs\EventMachine\Contracts\ReturnsResult;

/**
 * A test job actor that succeeds and returns a result.
 */
class SuccessfulTestJob implements ReturnsResult
{
    public function __construct(
        public readonly ?string $orderId = null,
    ) {}

    public function handle(): void
    {
        // Simulate work — no-op for testing
    }

    public function result(): array
    {
        return [
            'paymentId' => 'pay_test_123',
            'orderId'   => $this->orderId,
        ];
    }
}
