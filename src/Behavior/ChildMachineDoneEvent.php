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
     * Get the child's output data.
     *
     * If the child machine defines an `output` key on its final state, returns
     * only the exposed keys. Otherwise, returns the full child context.
     *
     * @param  string|null  $key  Dot-notation key to retrieve a specific value.
     */
    public function output(?string $key = null): mixed
    {
        $output = $this->payload['output'] ?? [];

        return $key !== null ? data_get($output, $key) : $output;
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
     * Get the child machine's final state key name.
     *
     * Returns the leaf key (e.g., 'approved'), not the full ID path.
     * Returns null for legacy events that don't carry final state info.
     */
    public function finalState(): ?string
    {
        return $this->payload['final_state'] ?? null;
    }

    /**
     * Create an instance for internal use.
     *
     * @param  array  $payload  The payload containing result, output, machine_id, machine_class.
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
