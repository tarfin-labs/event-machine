<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Contexts;

use Tarfinlabs\EventMachine\ContextManager;

class InvalidCastContext extends ContextManager
{
    public function __construct(
        public string $value = '',
    ) {}

    public static function casts(): array
    {
        return [
            'value' => \stdClass::class,
        ];
    }
}
