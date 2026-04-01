<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Tarfinlabs\EventMachine\Actor\Machine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Tarfinlabs\EventMachine\Exceptions\RestoringStateException;
use Tarfinlabs\EventMachine\Exceptions\InvalidMachineClassException;
use Tarfinlabs\EventMachine\Exceptions\MachineAlreadyRunningException;
use Tarfinlabs\EventMachine\Exceptions\NoTransitionDefinitionFoundException;

/**
 * Queue job that sends an event to a target machine asynchronously.
 *
 * Dispatched by InvokableBehavior::dispatchTo() and dispatchToParent().
 * Restores the target machine from its root_event_id, sends the event,
 * and handles the case where the target machine no longer exists.
 */
class SendToMachineJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * High tries to accommodate lock contention retries via release().
     * Each release() increments attempts — with 4+ concurrent events
     * to the same machine, each may need 3-5 release cycles.
     * maxExceptions limits actual failures (not release retries).
     */
    public int $tries = 25;

    public int $maxExceptions = 3;

    /**
     * @param  string  $machineClass  FQCN of the target Machine subclass.
     * @param  string  $rootEventId  The target machine's root_event_id.
     * @param  array<string, mixed>  $event  The event to send (array with 'type' and optional 'payload').
     */
    public function __construct(
        public readonly string $machineClass,
        public readonly string $rootEventId,
        public readonly array $event,
    ) {}

    public function handle(): void
    {
        if (!class_exists($this->machineClass) || !is_subclass_of($this->machineClass, Machine::class)) {
            throw InvalidMachineClassException::mustExtendMachine($this->machineClass);
        }

        try {
            /** @var Machine $targetMachine */
            $targetMachine                           = $this->machineClass::withDefinition($this->machineClass::definition());
            $targetMachine->definition->machineClass = $this->machineClass;
            $targetMachine->start($this->rootEventId);
            $targetMachine->send($this->event);
        } catch (RestoringStateException) {
            Log::warning('SendToMachineJob: target machine not found, discarding event.', [
                'machine_class' => $this->machineClass,
                'root_event_id' => $this->rootEventId,
                'event'         => $this->event,
            ]);
        } catch (MachineAlreadyRunningException) {
            // Another job holds the lock for this machine. Release back to queue
            // with a short delay so the current holder can finish and release the lock.
            // Without this, all retry attempts fire instantly and exhaust tries.
            $this->release(1);
        } catch (NoTransitionDefinitionFoundException) {
            // Machine is not in a state that handles this event yet.
            // Release back to queue — the machine may transition to a
            // valid state after other pending events are processed.
            $this->release(2);
        }
    }
}
