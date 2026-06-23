<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use LogicException;

class InvalidRouterConfigException extends LogicException
{
    public static function onlyAndExceptConflict(): self
    {
        return new self("MachineRouter: 'only' and 'except' cannot be used together.");
    }

    /**
     * @param  array<int, string>  $unknown
     * @param  array<int, string>  $available
     */
    public static function unknownEventTypes(string $filterKey, array $unknown, array $available): self
    {
        return new self(sprintf(
            "MachineRouter: unknown event types in '%s': %s. Available: %s",
            $filterKey,
            implode(', ', $unknown),
            implode(', ', $available),
        ));
    }

    /**
     * @param  array<int, string>  $eventTypes
     */
    public static function forwardedInMachineIdFor(array $eventTypes): self
    {
        return new self(sprintf(
            "MachineRouter: 'machineIdFor' cannot reference forwarded endpoints "
            .'(they inherit binding mode from parent model config): %s',
            implode(', ', $eventTypes),
        ));
    }

    /**
     * @param  array<int, string>  $eventTypes
     */
    public static function forwardedInModelFor(array $eventTypes): self
    {
        return new self(sprintf(
            "MachineRouter: 'modelFor' cannot reference forwarded endpoints "
            .'(they inherit binding mode from parent model config): %s',
            implode(', ', $eventTypes),
        ));
    }

    /**
     * @param  array<int, string>  $orphans
     */
    public static function orphanedMachineIdFor(array $orphans, string $context): self
    {
        return new self(sprintf(
            "MachineRouter: 'machineIdFor' references event types not in the registered endpoint set: %s%s",
            implode(', ', $orphans),
            $context,
        ));
    }

    /**
     * @param  array<int, string>  $orphans
     */
    public static function orphanedModelFor(array $orphans, string $context): self
    {
        return new self(sprintf(
            "MachineRouter: 'modelFor' references event types not in the registered endpoint set: %s%s",
            implode(', ', $orphans),
            $context,
        ));
    }

    public static function modelAndAttributeRequired(): self
    {
        return new self("MachineRouter: 'model' and 'attribute' are required when 'modelFor' is set.");
    }

    /**
     * @param  array<int, string>  $overlap
     */
    public static function overlappingBindingModes(array $overlap): self
    {
        return new self(
            "MachineRouter: events cannot be in both 'machineIdFor' and 'modelFor': ".implode(', ', $overlap)
        );
    }

    public static function forbiddenReadKey(string $key): self
    {
        return new self(sprintf(
            "MachineRouter: read definition cannot contain '%s' — reads are pure GET projections (no actions, GET-only).",
            $key,
        ));
    }

    /**
     * @param  array<int, string>  $recognized
     */
    public static function unknownReadKey(string $key, array $recognized): self
    {
        return new self(sprintf(
            "MachineRouter: unknown read option '%s'. Recognized: %s.",
            $key,
            implode(', ', $recognized),
        ));
    }

    public static function invalidReadUri(string $uri): self
    {
        return new self(sprintf(
            "MachineRouter: invalid read URI '%s' — must be non-empty, not just '/', and contain no route placeholders ({...}).",
            $uri,
        ));
    }

    public static function duplicateReadUri(string $uri): self
    {
        return new self(sprintf(
            "MachineRouter: duplicate read URI '%s' within one registration.",
            $uri,
        ));
    }

    public static function readRouteCollision(string $method, string $uri): self
    {
        return new self(sprintf(
            "MachineRouter: read route %s '%s' collides with an existing endpoint route in the same group.",
            $method,
            $uri,
        ));
    }
}
