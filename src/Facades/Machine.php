<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Class Machine Facade.
 *
 * This class extends the Facade class and represents a machine. It provides a static method to get the facade accessor.
 *
 * @see \Tarfinlabs\EventMachine\Machine
 *
 * @codeCoverageIgnore
 */
class Machine extends Facade
{
    /**
     * Get the access key for the facade.
     *
     * @return string The access key for the facade.
     */
    protected static function getFacadeAccessor(): string
    {
        return \Tarfinlabs\EventMachine\Definition\MachineDefinition::class;
    }
}
