<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Locks;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineStateLock;

class MachineLockHandle
{
    public function __construct(
        public readonly string $rootEventId,
        public readonly string $ownerId,
    ) {}

    public function release(): void
    {
        // Deregister from the process-local re-entrancy registry — paired with
        // the registration performed by MachineLockManager::acquire().
        unset(Machine::$heldLockIds[$this->rootEventId]);

        MachineStateLock::query()
            ->where('root_event_id', $this->rootEventId)
            ->where('owner_id', $this->ownerId)
            ->delete();
    }
}
