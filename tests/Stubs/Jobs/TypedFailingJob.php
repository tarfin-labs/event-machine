<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Jobs;

use Tarfinlabs\EventMachine\Behavior\MachineFailure;
use Tarfinlabs\EventMachine\Contracts\ProvidesFailure;
use Tarfinlabs\EventMachine\Tests\Stubs\Failures\PaymentFailure;

class TypedFailingJob implements ProvidesFailure
{
    public function __construct(
        private readonly string $orderId = '',
    ) {}

    public function handle(): void
    {
        throw new \RuntimeException('Payment gateway unavailable', 503);
    }

    public static function failure(\Throwable $e): MachineFailure
    {
        return PaymentFailure::fromException($e);
    }
}
