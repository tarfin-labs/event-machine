<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;

class ScenariosDisabledException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Scenarios are disabled. Set MACHINE_SCENARIOS_ENABLED=true in your environment to enable them.',
        );
    }
}
