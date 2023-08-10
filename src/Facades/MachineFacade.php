<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * The MachineFacade class represents a facade for accessing the Event Machine service container.
 */
class MachineFacade extends Facade
{
    /**
     * Get the service container key for the facade.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'event-machine';
    }
}
