<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Contexts;

use Tarfinlabs\EventMachine\ContextManager;

class LineItemDto extends ContextManager
{
    public function __construct(
        public string $name = '',
        public int $price = 0,
    ) {}
}
