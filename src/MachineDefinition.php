<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

class MachineDefinition
{
    private function __construct()
    {
    }

    public static function define(): self
    {
        return new self();
    }
}
