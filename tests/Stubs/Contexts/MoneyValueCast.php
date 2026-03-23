<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Contexts;

use Tarfinlabs\EventMachine\Contracts\ContextCast;

class MoneyValueCast implements ContextCast
{
    public function serialize(mixed $value): mixed
    {
        return $value->cents;
    }

    public function deserialize(mixed $value): mixed
    {
        return new MoneyValue(cents: (int) $value);
    }
}
