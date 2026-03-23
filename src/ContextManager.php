<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

use Illuminate\Support\Facades\Validator;
use Tarfinlabs\EventMachine\Exceptions\MachineContextValidationException;

/**
 * Class ContextManager.
 *
 * Base class for typed context classes. Extends TypedData for reflection-based
 * from()/toArray(), 4-layer cast resolution, and validation.
 *
 * Adds context-specific features: machine identity, get/set/has dict API,
 * computed context for API responses.
 */
class ContextManager extends TypedData
{
    // region Validation (context-specific exception)

    protected static function performValidation(array $data): void
    {
        $rules = static::rules();

        if ($rules === []) {
            return;
        }

        $validator = Validator::make($data, $rules, static::messages());

        if ($validator->fails()) {
            throw new MachineContextValidationException($validator);
        }
    }

    // endregion

    // region Property Access (engine dict API)

    /**
     * Get a value from the context by its key.
     * On typed contexts, accesses the public property directly.
     */
    public function get(string $key): mixed
    {
        return $this->{$key} ?? null;
    }

    /**
     * Set a value for a given key.
     * On typed contexts, sets the public property directly.
     */
    public function set(string $key, mixed $value): mixed
    {
        $this->{$key} = $value;

        return $value;
    }

    /**
     * Check if a key exists and optionally verify its type.
     * On typed contexts, checks property existence.
     */
    public function has(string $key, ?string $type = null): bool
    {
        $hasKey = property_exists($this, $key);

        if (!$hasKey || $type === null) {
            return $hasKey;
        }

        $value     = $this->get($key);
        $valueType = get_debug_type($value);

        return $valueType === $type;
    }

    /**
     * Remove a key from the context.
     * On typed contexts, sets the property to null.
     */
    public function remove(string $key): void
    {
        if (property_exists($this, $key)) {
            $this->{$key} = null;
        }
    }

    // endregion

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

    public function machineId(): ?string
    {
        return $this->internalMachineId;
    }

    public function parentMachineId(): ?string
    {
        return $this->internalParentRootEventId;
    }

    public function parentMachineClass(): ?string
    {
        return $this->internalParentMachineClass;
    }

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

    public function __set(string $name, mixed $value): void
    {
        $this->set($name, $value);
    }

    public function __get(string $name): mixed
    {
        return $this->get($name);
    }

    public function __isset(string $name): bool
    {
        return $this->has($name);
    }

    // endregion
}
