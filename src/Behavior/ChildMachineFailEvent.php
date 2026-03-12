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
     * Get the child's context at the time of failure (as array).
     *
     * @param  string|null  $key  Dot-notation key to retrieve a specific value.
     */
    public function childContext(?string $key = null): mixed
    {
        $context = $this->payload['child_context'] ?? [];

        return $key !== null ? data_get($context, $key) : $context;
    }

    /**
     * Create an instance for internal use.
     *
     * @param  array  $payload  The payload containing error_message, machine_id, machine_class, child_context.
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
