<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

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
        if ($this->data instanceof Optional) {
            return;
        }

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
        if ($this->data instanceof Optional) {
            return;
        }

        $this->data = array_merge($this->data, $contextData);
    }

    /**
     * Validates the current instance against its own rules.
     *
     * This method validates the current instance by calling the static validate() method on itself.
     * If validation fails, it throws a MachineContextValidationException with the validator object.
     *
     * @throws MachineContextValidationException when validation fails.
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
     * @param  array<mixed>|Arrayable<string, mixed>  $payload The payload to be validated and created from.
     *
     * @return static A new instance of the class created from the payload.
     *
     * @throws MachineContextValidationException When validation fails.
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
}
