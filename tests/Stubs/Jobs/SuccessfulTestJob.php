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
        public readonly ?string $order_id = null,
    ) {}

    public function handle(): void
    {
        // Simulate work — no-op for testing
    }

    public function result(): array
    {
        return [
            'payment_id' => 'pay_test_123',
            'order_id'   => $this->order_id,
        ];
    }
}
