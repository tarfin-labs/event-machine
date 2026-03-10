<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability;

class CounterService
{
    public function increment(int $value): int
    {
        return $value + 1;
    }
}
