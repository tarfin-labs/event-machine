<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Contexts;

use Tarfinlabs\EventMachine\ContextManager;

class PaymentContext extends ContextManager
{
    public function __construct(
        public ?MoneyValue $amount = null,
    ) {}
}
