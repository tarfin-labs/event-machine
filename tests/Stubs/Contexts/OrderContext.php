<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Contexts;

use Illuminate\Support\Collection;
use Tarfinlabs\EventMachine\ContextManager;

class OrderContext extends ContextManager
{
    public function __construct(
        public Collection $items = new Collection(),
    ) {}

    public static function casts(): array
    {
        return [
            'items' => [LineItemDto::class],
        ];
    }
}
