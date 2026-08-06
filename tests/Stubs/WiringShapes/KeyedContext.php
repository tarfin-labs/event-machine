<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes;

use Tarfinlabs\EventMachine\ContextManager;

class KeyedContext extends ContextManager
{
    public ?string $known = null;
}
