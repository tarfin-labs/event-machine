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

    public static function undefinedOutput(string $outputKey): self
    {
        return new self("Endpoint references undefined output behavior `{$outputKey}`. Ensure it is listed in the behavior 'outputs' array or is a valid FQCN.");
    }

    public static function invalidAction(string $actionClass): self
    {
        return new self("Endpoint action `{$actionClass}` must extend MachineEndpointAction.");
    }

    public static function forwardOverlapsEndpoint(string $stateId, string $parentEventType): self
    {
        return new self(
            "State '{$stateId}' forwards '{$parentEventType}' which is also declared in parent's "
            ."endpoints. Remove '{$parentEventType}' from endpoints — forward is the single source of truth for child events."
        );
    }

    public static function forwardOverlapsBehaviorEvents(string $stateId, string $parentEventType): self
    {
        return new self(
            "State '{$stateId}' forwards '{$parentEventType}' which is also declared in parent's "
            ."behavior.events. Remove '{$parentEventType}' from behavior.events — forward auto-discovers child events."
        );
    }

    public static function forwardCollision(string $parentEventType): self
    {
        return new self(
            "Forward event '{$parentEventType}' is declared in multiple delegating states. "
            ."Use rename syntax to disambiguate (e.g., 'CANCEL_PAYMENT' => 'CANCEL')."
        );
    }
}
