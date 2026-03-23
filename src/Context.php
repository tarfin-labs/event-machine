<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

use Illuminate\Support\Arr;

/**
 * Class Context.
 *
 * Bag mode context — a simple key-value store for managing context data.
 * Used internally when machine config specifies 'context' => [...] (array).
 * Users don't extend this class directly; they create typed context classes
 * extending ContextManager for typed property access.
 */
class Context extends ContextManager
{
    public function __construct(private array $bag = []) {}

    // region Factory & Serialization

    public static function from(array $data): static
    {
        return new static(bag: $data);
    }

    public function toArray(): array
    {
        return $this->bag;
    }

    // endregion

    // region Dict API

    public function get(string $key): mixed
    {
        return Arr::get($this->bag, $key);
    }

    public function set(string $key, mixed $value): mixed
    {
        $this->bag[$key] = $value;

        return $value;
    }

    public function has(string $key, ?string $type = null): bool
    {
        $hasKey = Arr::has($this->bag, $key);

        if (!$hasKey || $type === null) {
            return $hasKey;
        }

        $value     = $this->get($key);
        $valueType = get_debug_type($value);

        return $valueType === $type;
    }

    public function remove(string $key): void
    {
        unset($this->bag[$key]);
    }

    // endregion

    // region Magic (property-like access on bag)

    public function __get(string $name): mixed
    {
        return $this->get($name);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->set($name, $value);
    }

    public function __isset(string $name): bool
    {
        return $this->has($name);
    }

    // endregion
}
