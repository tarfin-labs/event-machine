<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use ReflectionClass;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Exceptions\MachineInputValidationException;

/**
 * Base class for typed input contracts.
 *
 * Defines what data a child machine or job requires from its parent.
 * Consumed at the delegation boundary — validates, merges into context, then is gone.
 *
 * @phpstan-consistent-constructor
 */
abstract class MachineInput
{
    /**
     * Auto-resolve from context: constructor param names match camelCase context keys directly.
     *
     * Required params without matching context key throw MachineInputValidationException.
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
                throw MachineInputValidationException::missingField(
                    inputClass: static::class,
                    paramName: $key,
                    availableKeys: is_array($context->data) ? array_keys($context->data) : array_keys(get_object_vars($context->data)),
                );
            }
        }

        return new static(...$params);
    }

    /**
     * Serialize to array for context merging and queue transport.
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
