<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

use Illuminate\Support\Arr;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Tarfinlabs\EventMachine\Exceptions\MachineContextValidationException;

/**
 * Class ContextManager.
 *
 * ContextManager is a class that provides a simple key-value store
 * for managing and manipulating context data within the event machine.
 */
class ContextManager extends Data
{
    /**
     * Create a new ContextManager instance.
     *
     * @param  Optional|array  $data  An optional initial array of key-value pairs.
     */
    public function __construct(
        #[ArrayType]
        public array|Optional $data = [],
    ) {}

    /**
     * Get a value from the context by its key.
     *
     * @param  string  $key  The key of the value to retrieve.
     *
     * @return mixed The value associated with the given key, or null if the key does not exist.
     */
    public function get(string $key): mixed
    {
        return match (true) {
            static::class === self::class      => Arr::get($this->data, $key),
            is_subclass_of($this, self::class) => (new \ReflectionProperty($this, $key))->getValue($this),
        };
    }

    /**
     * Sets a value for a given key.
     *
     * This method is used to set a value for a given key in the data array.
     * If the current class is the same as the class of the object, the
     * value is set in the data array. If the current class is a
     * subclass of the class of the object, the value is set as
     * a property of the object.
     *
     * @param  string  $key  The key for which to set the value.
     * @param  mixed  $value  The value to set for the given key.
     */
    public function set(string $key, mixed $value): mixed
    {
        if ($this->data instanceof Optional) {
            return null;
        }

        match (true) {
            static::class === self::class      => $this->data[$key] = $value,
            is_subclass_of($this, self::class) => (new \ReflectionProperty($this, $key))->setValue($this, $value),
        };

        return $value;
    }

    /**
     * Determines if a key-value pair exists and optionally checks its type.
     *
     * This method checks if the context contains the given key and, if a type is
     * specified, whether the value associated with that key is of the given type.
     * If no type is specified, only existence is checked. If the key does not
     * exist, or if the type does not match, the method returns false.
     *
     * @param  string  $key  The key to check for existence.
     * @param  string|null  $type  The type to check for the value. If null,
     *                             only existence is checked.
     *
     * @return bool True if the key exists and (if a type is specified)
     *              its value is of the given type. False otherwise.
     */
    public function has(string $key, ?string $type = null): bool
    {
        $hasKey = match (true) {
            static::class === self::class      => Arr::has($this->data, $key),
            is_subclass_of($this, self::class) => property_exists($this, $key),
        };

        if (!$hasKey || $type === null) {
            return $hasKey;
        }

        $value     = $this->get($key);
        $valueType = get_debug_type($value);

        return $valueType === $type;
    }

    /**
     * Remove a key-value pair from the context by its key.
     *
     * @param  string  $key  The key to remove from the context.
     */
    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    /**
     * Validates the current instance against its own rules.
     *
     * This method validates the current instance by calling the static validate() method on itself.
     * If validation fails, it throws a MachineContextValidationException with the validator object.
     */
    public function selfValidate(): void
    {
        try {
            static::validate($this);
        } catch (ValidationException $e) {
            throw new MachineContextValidationException($e->validator);
        }
    }

    /**
     * Validates the given payload and creates an instance from it.
     *
     * This method first validates the given payload using the static validate() method.
     * If the validation passes, it creates a new instance of the class using the
     * static from() method and returns it.
     * If validation fails, it throws a MachineContextValidationException.
     *
     * @param  array|Arrayable  $payload  The payload to be validated and created from.
     *
     * @return static A new instance of the class created from the payload.
     */
    public static function validateAndCreate(array|Arrayable $payload): static
    {
        try {
            static::validate($payload);
        } catch (ValidationException $e) {
            throw new MachineContextValidationException($e->validator);
        }

        return static::from($payload);
    }

    // region Machine Identity

    /** The machine's root_event_id — separate from context data to avoid polluting serialized state. */
    protected ?string $internalMachineId = null;

    /** The parent machine's root_event_id (if this is a child machine). */
    protected ?string $internalParentRootEventId = null;

    /** The parent machine's FQCN (if this is a child machine). */
    protected ?string $internalParentMachineClass = null;

    /**
     * Set machine identity properties.
     *
     * Called by the engine during create()/start() — not stored in the data array.
     */
    public function setMachineIdentity(string $machineId, ?string $parentRootEventId = null, ?string $parentMachineClass = null): void
    {
        $this->internalMachineId          = $machineId;
        $this->internalParentRootEventId  = $parentRootEventId;
        $this->internalParentMachineClass = $parentMachineClass;
    }

    /**
     * Get the machine's root_event_id.
     */
    public function machineId(): ?string
    {
        return $this->internalMachineId;
    }

    /**
     * Get the parent machine's root_event_id (if this is a child machine).
     *
     * Returns null if this machine was not invoked by a parent.
     */
    public function parentMachineId(): ?string
    {
        return $this->internalParentRootEventId;
    }

    /**
     * Get the parent machine's FQCN (if this is a child machine).
     *
     * Returns null if this machine was not invoked by a parent.
     */
    public function parentMachineClass(): ?string
    {
        return $this->internalParentMachineClass;
    }

    /**
     * Check if this machine was invoked by a parent machine.
     */
    public function isChildMachine(): bool
    {
        return $this->internalParentRootEventId !== null;
    }

    // endregion

    // region Computed Context

    /**
     * Define computed key-value pairs derived from context data.
     *
     * Override in subclasses to expose calculated values in API responses.
     * These are NOT persisted to the database — they are recomputed on every response.
     *
     * @return array<string, mixed>
     */
    protected function computedContext(): array
    {
        return [];
    }

    /**
     * Serialize context for API responses, including computed values.
     *
     * @return array<string, mixed>
     */
    public function toResponseArray(): array
    {
        return array_merge($this->toArray(), $this->computedContext());
    }

    // endregion

    // region Magic Setup

    /**
     * Set a value in the context by its name.
     *
     * @param  string  $name  The name of the value to set.
     * @param  mixed  $value  The value to set.
     */
    public function __set(string $name, mixed $value): void
    {
        $this->set($name, $value);
    }

    /**
     * Magic method to dynamically retrieve a value from the context by its key.
     *
     * @param  string  $name  The key of the value to retrieve.
     *
     * @return mixed The value associated with the given key, or null if the key does not exist.
     */
    public function __get(string $name): mixed
    {
        return $this->get($name);
    }

    /**
     * Checks if a property is set on the object.
     *
     * @param  string  $name  The name of the property to check.
     *
     * @return bool True if the property exists and is set, false otherwise.
     */
    public function __isset(string $name): bool
    {
        return $this->has($name);
    }

    // endregion
}
