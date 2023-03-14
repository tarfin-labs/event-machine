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

    public static function define(?array $definition = null): State
    {
        return new State(
            name: $definition['name'] ?? null,
            parent: $definition['parent'] ?? null,
            version: $definition['version'] ?? null,
            initialState: $definition['initial_state'] ?? null,
            value: $definition['value'] ?? null,
            states: $definition['states'] ?? null,
        );
    }
}
