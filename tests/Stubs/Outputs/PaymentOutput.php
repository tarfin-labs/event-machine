<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Outputs;

use Tarfinlabs\EventMachine\Behavior\MachineOutput;

class PaymentOutput extends MachineOutput
{
    public function __construct(
        public readonly string $paymentId,
        public readonly string $status,
        public readonly ?string $transactionRef = null,
    ) {}
}
