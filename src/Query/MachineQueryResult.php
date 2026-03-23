<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Query;

use JsonSerializable;
use Illuminate\Support\Carbon;
use Tarfinlabs\EventMachine\Actor\Machine;
use Illuminate\Contracts\Support\Arrayable;
use Tarfinlabs\EventMachine\ContextManager;

/**
 * Lightweight result object returned by MachineQueryBuilder.
 *
 * Wraps a single machine instance's current state data without
 * performing expensive event-history restoration. Full Machine
 * restore happens lazily on first access via machine().
 *
 * @implements Arrayable<string, mixed>
 */
class MachineQueryResult implements Arrayable, JsonSerializable
{
    private ?Machine $cachedMachine = null;

    /**
     * @param  string  $machineId  The machine instance's root_event_id.
     * @param  string  $stateId  The most recently entered state_id (representative).
     * @param  Carbon  $stateEnteredAt  When the representative state was entered.
     * @param  array<int, string>  $stateIds  ALL active state_ids for this instance (1 for simple, N for parallel).
     * @param  string  $machineClass  FQCN of the Machine subclass (internal, used for lazy restore).
     */
    public function __construct(
        public readonly string $machineId,
        public readonly string $stateId,
        public readonly Carbon $stateEnteredAt,
        public readonly array $stateIds,
        private readonly string $machineClass,
    ) {}

    /**
     * Lazily restore the full Machine instance from event history.
     *
     * The result is cached — subsequent calls return the same instance.
     */
    public function machine(): Machine
    {
        return $this->cachedMachine ??= ($this->machineClass)::create(state: $this->machineId);
    }

    /**
     * Shortcut to get the machine's context without holding the Machine reference.
     */
    public function context(): ContextManager
    {
        return $this->machine()->state->context;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'machine_id'       => $this->machineId,
            'state_id'         => $this->stateId,
            'state_ids'        => $this->stateIds,
            'state_entered_at' => $this->stateEnteredAt->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
