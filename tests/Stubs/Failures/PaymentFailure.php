<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Failures;

use Tarfinlabs\EventMachine\Behavior\MachineFailure;

class PaymentFailure extends MachineFailure
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $message,
        public readonly ?string $gatewayResponse = null,
    ) {}
}
