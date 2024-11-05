<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * The EventMachine class represents a facade for accessing the Event Machine service container.
 *
 * @method static void resetAllFakes() Resets all fake invocations to their default state.
 *
 * @see \Tarfinlabs\EventMachine\EventMachine
 */
class EventMachine extends Facade
{
    /**
     * Get the service container key for the facade.
     */
    protected static function getFacadeAccessor(): string
    {
        return \Tarfinlabs\EventMachine\EventMachine::class;
    }
}
