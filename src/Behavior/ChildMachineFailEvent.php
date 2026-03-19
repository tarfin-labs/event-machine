<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Tarfinlabs\EventMachine\Enums\SourceType;

/**
 * Typed event fired when a child machine fails.
 *
 * Provides typed accessors for error details, child identity,
 * and the child's context at the time of failure.
 */
class ChildMachineFailEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'CHILD_MACHINE_FAIL';
    }

    /**
     * Get the error message from the child failure.
     */
    public function errorMessage(): ?string
    {
        return $this->payload['error_message'] ?? null;
    }

    /**
     * Get the error code from the child failure.
     *
     * Populated automatically from $exception->getCode(). For structured
     * error codes (e.g., 'E311'), implement ProvidesFailureContext on your
     * job and return the code in the output array instead.
     */
    public function errorCode(): int|string|null
    {
        return $this->payload['error_code'] ?? null;
    }

    /**
     * Get the child machine's root_event_id.
     */
    public function childMachineId(): string
    {
        return $this->payload['machine_id'];
    }

    /**
     * Get the child machine's FQCN.
     */
    public function childMachineClass(): string
    {
        return $this->payload['machine_class'];
    }

    /**
     * Get the child's output data at the time of failure.
     *
     * @param  string|null  $key  Dot-notation key to retrieve a specific value.
     */
    public function output(?string $key = null): mixed
    {
        $output = $this->payload['output'] ?? [];

        return $key !== null ? data_get($output, $key) : $output;
    }

    /**
     * Create an instance for internal use.
     *
     * @param  array  $payload  The payload containing error_message, machine_id, machine_class, output.
     */
    public static function forChild(array $payload): static
    {
        return static::from([
            'type'    => static::getType(),
            'payload' => $payload,
            'version' => 1,
            'source'  => SourceType::INTERNAL,
        ]);
    }

    /**
     * Create an instance with sensible defaults for unit testing guards/actions.
     *
     * Only the data you care about needs to be provided — machine identity
     * fields are filled with harmless defaults.
     *
     * @param  array  $attributes  Payload keys to override (error_message, output, machine_id, machine_class).
     */
    public static function forTesting(array $attributes = []): static
    {
        return static::forChild(array_merge([
            'error_message' => null,
            'error_code'    => null,
            'output'        => [],
            'machine_id'    => 'test',
            'machine_class' => 'TestMachine',
        ], $attributes));
    }
}
