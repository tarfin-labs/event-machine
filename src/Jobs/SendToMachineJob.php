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
     * @param  string  $machineClass  FQCN of the target Machine subclass.
     * @param  string  $rootEventId  The target machine's root_event_id.
     * @param  array  $event  The event to send (array with 'type' and optional 'payload').
     */
    public function __construct(
        public readonly string $machineClass,
        public readonly string $rootEventId,
        public readonly array $event,
    ) {}

    public function handle(): void
    {
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
        } catch (NoTransitionDefinitionFoundException) {
            Log::warning('SendToMachineJob: failed to deliver event, target machine cannot handle it in current state.', [
                'machine_class' => $this->machineClass,
                'root_event_id' => $this->rootEventId,
                'event'         => $this->event,
            ]);
        }
    }
}
