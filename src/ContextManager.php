<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Attributes\Validation\ArrayType;

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
     * @param  \Spatie\LaravelData\Optional|array  $data  An optional initial array of key-value pairs.
     */
    public function __construct(
        #[ArrayType]
        public array|Optional $data = [],
    ) {
    }

    /**
     * Get a value from the context by its key.
     *
     * @param  string  $key The key of the value to retrieve.
     *
     * @return mixed The value associated with the given key, or null if the key does not exist.
     */
    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    /**
     * Set a value in the context with the given key.
     *
     * @param  string  $key The key to associate with the value.
     * @param  mixed  $value The value to store in the context.
     */
    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    /**
     * Check if the context contains the given key.
     *
     *@param  string  $key The key to check for existence.
     *
     *@return bool True if the key exists in the context, false otherwise.
     */
    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /**
     * Remove a key-value pair from the context by its key.
     *
     * @param  string  $key The key to remove from the context.
     */
    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    /**
     * Apply and merge the given context data with the existing context data.
     *
     * This method merges the given context data array with the existing
     * context data array. The existing data will be overwritten by the
     * new data if there are any conflicts.
     *
     * @param  array  $contextData The context data array to merge.
     */
    public function applyContextData(array $contextData): void
    {
        $this->data = array_merge($this->data, $contextData);
    }

    /**
     * Validates the current instance.
     *
     * This method validates the current instance by calling
     * the static validate() method on itself.
     */
    public function selfValidate(): void
    {
        $this::validate($this);
    }
}
