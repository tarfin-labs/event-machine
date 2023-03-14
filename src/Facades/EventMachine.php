<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Tarfinlabs\EventMachine\EventMachine
 */
class EventMachine extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Tarfinlabs\EventMachine\EventMachine::class;
    }
}
