<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;

class MachineDiscoveryException extends RuntimeException
{
    public static function noSearchPaths(): self
    {
        return new self(
            'No valid search paths found for Machine classes. '.
            'If you are using event-machine package, please ensure your Machine classes are in the app/ directory.'
        );
    }
}
