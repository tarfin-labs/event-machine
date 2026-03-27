<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Tarfinlabs\EventMachine\Actor\Machine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Tarfinlabs\EventMachine\Enums\InternalEvent;
use Tarfinlabs\EventMachine\Locks\MachineLockManager;
use Tarfinlabs\EventMachine\Exceptions\MachineLockTimeoutException;

/**
 * Queue job that executes a queued listener action on a worker.
 *
 * Restores the machine from its root_event_id, records started/completed
 * internal events, runs the listener action, and persists.
 */
class ListenerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $machineClass,
        public readonly string $rootEventId,
        public readonly string $actionClass,
    ) {}

    public function handle(): void
    {
        if (!class_exists($this->machineClass) || !is_subclass_of($this->machineClass, Machine::class)) {
            return;
        }

        // Acquire lock to prevent concurrent ListenerJobs from overwriting
        // each other's context changes (lost-update). This happens when
        // exit + transition listeners dispatch simultaneously for the same machine.
        // Re-entrant check: in sync queue mode, send() may already hold the lock.
        $alreadyLocked = isset(Machine::$heldLockIds[$this->rootEventId]);
        $lockHandle    = null;

        if (!$alreadyLocked) {
            try {
                $lockHandle = MachineLockManager::acquire(
                    rootEventId: $this->rootEventId,
                    timeout: 10,
                    ttl: 30,
                    context: 'listener_job:'.class_basename($this->actionClass),
                );
            } catch (MachineLockTimeoutException) {
                // Another job holds the lock — release back to queue with delay.
                $this->release(2);

                return;
            }
        }

        try {
            $machine = $this->machineClass::create(state: $this->rootEventId);

            $machine->state->setInternalEventBehavior(
                type: InternalEvent::LISTEN_QUEUE_STARTED,
                placeholder: $this->actionClass,
            );

            $machine->definition->runAction(
                actionDefinition: $this->actionClass,
                state: $machine->state,
            );

            $machine->state->setInternalEventBehavior(
                type: InternalEvent::LISTEN_QUEUE_COMPLETED,
                placeholder: $this->actionClass,
            );

            $machine->persist();
        } finally {
            $lockHandle->release();
        }
    }
}
