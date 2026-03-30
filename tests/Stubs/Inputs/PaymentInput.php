<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Inputs;

use Tarfinlabs\EventMachine\Behavior\MachineInput;

class PaymentInput extends MachineInput
{
    public function __construct(
        public readonly string $orderId,
        public readonly int $amount,
        public readonly string $currency = 'TRY',
    ) {}
}
