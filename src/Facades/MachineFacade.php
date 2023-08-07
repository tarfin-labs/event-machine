<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Facades;

use Illuminate\Support\Facades\Facade;

class MachineFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'event-machine';
    }
}
