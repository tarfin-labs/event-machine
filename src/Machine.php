<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

class Machine
{
    public static function define(): State
    {
        return new State();
    }
}
