<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use ReflectionClass;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Exceptions\MachineOutputResolutionException;

/**
 * Base class for typed output contracts.
 *
 * Defines what data a machine produces when it reaches a given state.
 * Plugs into v9's output type dispatch as a new case alongside OutputBehavior.
 * Works on any state (not just final), serves both delegation and HTTP responses.
 *
 * @phpstan-consistent-constructor
 */
abstract class MachineOutput
{
    /**
     * Auto-resolve from context: constructor param names match camelCase context keys directly.
     *
     * Required params without matching context key throw MachineOutputResolutionException.
     * Optional/nullable params fall back to default value or null.
     */
    public static function fromContext(ContextManager $context): static
    {
        $reflection = new ReflectionClass(static::class);
        $params     = [];

        foreach ($reflection->getConstructor()->getParameters() as $param) {
            $key   = $param->getName();
            $value = $context->get($key);

            if ($value !== null) {
                $params[$key] = $value;
            } elseif ($param->isDefaultValueAvailable()) {
                $params[$key] = $param->getDefaultValue();
            } elseif ($param->getType()?->allowsNull()) {
                $params[$key] = null;
            } else {
                throw MachineOutputResolutionException::missingField(
                    outputClass: static::class,
                    paramName: $key,
                    availableKeys: is_array($context->data) ? array_keys($context->data) : array_keys(get_object_vars($context->data)),
                );
            }
        }

        return new static(...$params);
    }

    /**
     * Serialize for ChildMachineDoneEvent payload, HTTP response envelope, and queue transport.
     */
    public function toArray(): array
    {
        $result     = [];
        $reflection = new ReflectionClass(static::class);

        foreach ($reflection->getConstructor()->getParameters() as $param) {
            $property                  = $reflection->getProperty($param->getName());
            $result[$param->getName()] = $property->getValue($this);
        }

        return $result;
    }
}
