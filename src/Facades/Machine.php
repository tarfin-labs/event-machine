<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Tarfinlabs\EventMachine\Machine
 *
 * @codeCoverageIgnore
 */
class Machine extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Tarfinlabs\EventMachine\Definition\MachineDefinition::class;
    }
}
