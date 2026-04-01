<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Throwable;
use ReflectionClass;
use Tarfinlabs\EventMachine\Exceptions\MachineFailureResolutionException;

/**
 * Base class for typed failure contracts.
 *
 * Maps exceptions to structured error data when a child throws.
 * Used for both machine delegation and job delegation.
 * Sensible default maps $message/$code/$previous from Throwable properties.
 *
 * @phpstan-consistent-constructor
 */
abstract class MachineFailure
{
    /** Known Throwable property getters for auto-mapping. */
    private const array THROWABLE_GETTERS = [
        'message'  => 'getMessage',
        'code'     => 'getCode',
        'previous' => 'getPrevious',
        'file'     => 'getFile',
        'line'     => 'getLine',
    ];

    /**
     * Map an exception to a structured failure object.
     *
     * Sensible default: maps constructor params to Throwable properties.
     *   $message → getMessage(), $code → getCode(), $previous → getPrevious()
     * Unknown params: nullable → null, has default → default, required → MachineFailureResolutionException.
     * Override for domain-specific exception mapping.
     */
    public static function fromException(Throwable $e): static
    {
        $reflection = new ReflectionClass(static::class);
        $params     = [];

        foreach ($reflection->getConstructor()->getParameters() as $param) {
            $name = $param->getName();

            if (isset(self::THROWABLE_GETTERS[$name])) {
                $params[$name] = match (self::THROWABLE_GETTERS[$name]) {
                    'getMessage'  => $e->getMessage(),
                    'getCode'     => $e->getCode(),
                    'getPrevious' => $e->getPrevious(),
                    'getFile'     => $e->getFile(),
                    'getLine'     => $e->getLine(),
                };
            } elseif ($param->isDefaultValueAvailable()) {
                $params[$name] = $param->getDefaultValue();
            } elseif ($param->getType()?->allowsNull()) {
                $params[$name] = null;
            } else {
                throw MachineFailureResolutionException::unresolvedParam(
                    failureClass: static::class,
                    paramName: $name,
                );
            }
        }

        return new static(...$params);
    }

    /**
     * Serialize for ChildMachineFailEvent payload and queue transport.
     *
     * @return array<string, mixed>
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
