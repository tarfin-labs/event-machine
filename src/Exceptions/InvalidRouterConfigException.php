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

    public static function unknownEventTypes(string $filterKey, array $unknown, array $available): self
    {
        return new self(sprintf(
            "MachineRouter: unknown event types in '%s': %s. Available: %s",
            $filterKey,
            implode(', ', $unknown),
            implode(', ', $available),
        ));
    }

    public static function forwardedInMachineIdFor(array $eventTypes): self
    {
        return new self(sprintf(
            "MachineRouter: 'machineIdFor' cannot reference forwarded endpoints "
            .'(they inherit binding mode from parent model config): %s',
            implode(', ', $eventTypes),
        ));
    }

    public static function forwardedInModelFor(array $eventTypes): self
    {
        return new self(sprintf(
            "MachineRouter: 'modelFor' cannot reference forwarded endpoints "
            .'(they inherit binding mode from parent model config): %s',
            implode(', ', $eventTypes),
        ));
    }

    public static function orphanedMachineIdFor(array $orphans, string $context): self
    {
        return new self(sprintf(
            "MachineRouter: 'machineIdFor' references event types not in the registered endpoint set: %s%s",
            implode(', ', $orphans),
            $context,
        ));
    }

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

    public static function overlappingBindingModes(array $overlap): self
    {
        return new self(
            "MachineRouter: events cannot be in both 'machineIdFor' and 'modelFor': ".implode(', ', $overlap)
        );
    }
}
