<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Tarfinlabs\EventMachine\ContextManager;

abstract class ContextBehavior extends ContextManager
{
    public function __construct()
    {
        parent::__construct(static::define());
    }

    abstract public static function define(): array;
}
