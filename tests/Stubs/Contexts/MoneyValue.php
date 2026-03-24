<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Contexts;

class MoneyValue
{
    public function __construct(
        public readonly int $cents,
    ) {}
}
