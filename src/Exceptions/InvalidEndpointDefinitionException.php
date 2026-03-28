<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;

class InvalidEndpointDefinitionException extends RuntimeException
{
    public static function undefinedEvent(string $eventType): self
    {
        return new self("Endpoint references undefined event type `{$eventType}`. Ensure it is listed in the behavior 'events' array.");
    }

    public static function undefinedResult(string $resultKey): self
    {
        return new self("Endpoint references undefined output behavior `{$resultKey}`. Ensure it is listed in the behavior 'outputs' array or is a valid FQCN.");
    }

    public static function invalidAction(string $actionClass): self
    {
        return new self("Endpoint action `{$actionClass}` must extend MachineEndpointAction.");
    }
}
