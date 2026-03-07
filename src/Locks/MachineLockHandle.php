<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Locks;

use Tarfinlabs\EventMachine\Models\MachineStateLock;

class MachineLockHandle
{
    public function __construct(
        public readonly string $rootEventId,
        public readonly string $ownerId,
    ) {}

    public function release(): void
    {
        MachineStateLock::query()
            ->where('root_event_id', $this->rootEventId)
            ->where('owner_id', $this->ownerId)
            ->delete();
    }

    public function extend(int $seconds): void
    {
        MachineStateLock::query()
            ->where('root_event_id', $this->rootEventId)
            ->where('owner_id', $this->ownerId)
            ->update(['expires_at' => now()->addSeconds($seconds)]);
    }
}
