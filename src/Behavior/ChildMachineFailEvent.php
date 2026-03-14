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
}
