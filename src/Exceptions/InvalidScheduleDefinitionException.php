<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;

class InvalidScheduleDefinitionException extends RuntimeException
{
    public static function undefinedEvent(string $eventType): self
    {
        return new self("Schedule references undefined event type `{$eventType}`. Ensure the event is handled in 'on' or 'states' transitions.");
    }
}
