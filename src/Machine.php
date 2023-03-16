<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

class Machine
{
    /**
     * Machine class constructor.
     *
     * This method is declared as private to prevent direct instantiation of the Machine class.
     * Instead, it should be called by static factory method ({@see \Tarfinlabs\EventMachine\Facades\Machine::define()}).
     */
    private function __construct()
    {
    }

    public static function define(
        ?array $config = null,
    ): EventMachine {
        return new EventMachine(
            config: $config,
        );
    }
}
