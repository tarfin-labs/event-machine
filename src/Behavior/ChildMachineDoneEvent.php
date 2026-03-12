<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Tarfinlabs\EventMachine\Enums\SourceType;

/**
 * Typed event fired when a child machine reaches a final state.
 *
 * Provides typed accessors for child result data, context, and identity
 * instead of requiring raw payload array access.
 */
class ChildMachineDoneEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'CHILD_MACHINE_DONE';
    }

    /**
     * Get the child's ResultBehavior output.
     *
     * @param  string|null  $key  Dot-notation key to retrieve a specific value.
     */
    public function result(?string $key = null): mixed
    {
        $result = $this->payload['result'] ?? null;

        return $key !== null ? data_get($result, $key) : $result;
    }

    /**
     * Get the child's final context (as array).
     *
     * @param  string|null  $key  Dot-notation key to retrieve a specific value.
     */
    public function childContext(?string $key = null): mixed
    {
        $context = $this->payload['child_context'] ?? [];

        return $key !== null ? data_get($context, $key) : $context;
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
     * Create an instance for internal use.
     *
     * @param  array  $payload  The payload containing result, child_context, machine_id, machine_class.
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
