<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Jobs;

use Tarfinlabs\EventMachine\Contracts\ReturnsOutput;

/**
 * A test job actor that succeeds and returns output.
 */
class SuccessfulTestJob implements ReturnsOutput
{
    public function __construct(
        public readonly ?string $orderId = null,
    ) {}

    public function handle(): void
    {
        // Simulate work — no-op for testing
    }

    public function output(): array
    {
        return [
            'paymentId' => 'pay_test_123',
            'orderId'   => $this->orderId,
        ];
    }
}
