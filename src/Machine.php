<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

class Machine
{
    public static function define(?array $definition = null): State
    {
        return new State(
            name: $definition['name'] ?? null,
        );
    }
}
