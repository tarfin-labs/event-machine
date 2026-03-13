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
use Tarfinlabs\EventMachine\Behavior\ChildMachineDoneEvent;
use Tarfinlabs\EventMachine\Behavior\ChildMachineFailEvent;

/**
 * Queue job that restores a parent machine and routes @done/@fail
 * after a child machine completes asynchronously.
 *
 * Idempotent: checks that the parent is still in the invoking state
 * before routing. Handles missing parent gracefully (logs and discards).
 */
class ChildMachineCompletionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  string  $parentRootEventId  The parent machine's root_event_id.
     * @param  string  $parentMachineClass  FQCN of the parent machine.
     * @param  string  $parentStateId  The parent state that invoked the child.
     * @param  string  $childMachineClass  FQCN of the child machine.
     * @param  string|null  $childRootEventId  The child's root_event_id (null on pre-start failure).
     * @param  bool  $success  Whether the child completed successfully.
     * @param  mixed  $result  The child's ResultBehavior output (for @done).
     * @param  array  $childContextData  The child's final context data (for @done).
     * @param  string|null  $errorMessage  Error message (for @fail).
     */
    public function __construct(
        public readonly string $parentRootEventId,
        public readonly string $parentMachineClass,
        public readonly string $parentStateId,
        public readonly string $childMachineClass,
        public readonly ?string $childRootEventId = null,
        public readonly bool $success = true,
        public readonly mixed $result = null,
        public readonly array $childContextData = [],
        public readonly ?string $errorMessage = null,
    ) {}

    public function handle(): void
    {
        // 1. Restore parent machine from DB
        try {
            /** @var Machine $parentMachine */
            $parentMachine = $this->parentMachineClass::create(state: $this->parentRootEventId);
        } catch (\Throwable $e) {
            logger()->warning('ChildMachineCompletionJob: could not restore parent machine', [
                'parent_root_event_id' => $this->parentRootEventId,
                'parent_machine_class' => $this->parentMachineClass,
                'error'                => $e->getMessage(),
            ]);

            return;
        }

        // 2. Pre-lock idempotency check: is parent still in the invoking state?
        if ($parentMachine->state->currentStateDefinition->id !== $this->parentStateId) {
            return;
        }

        // 3. Acquire lock for parent state mutation
        $lockHandle = MachineLockManager::acquire(
            rootEventId: $this->parentRootEventId,
            timeout: 30,
            ttl: 60,
            context: 'child_machine_completion',
        );

        try {
            // 4. Re-check under lock (double-guard pattern)
            /** @var Machine $freshParent */
            $freshParent = $this->parentMachineClass::create(state: $this->parentRootEventId);

            if ($freshParent->state->currentStateDefinition->id !== $this->parentStateId) {
                return;
            }

            $stateDefinition = $freshParent->state->currentStateDefinition;

            // 5. Route @done or @fail on the parent
            if ($this->success) {
                $freshParent->state->setInternalEventBehavior(
                    type: InternalEvent::CHILD_MACHINE_DONE,
                    placeholder: $this->childMachineClass,
                );

                $doneEvent = ChildMachineDoneEvent::forChild([
                    'result'        => $this->result,
                    'child_context' => $this->childContextData,
                    'machine_id'    => $this->childRootEventId ?? '',
                    'machine_class' => $this->childMachineClass,
                ]);

                $freshParent->definition->routeChildDoneEvent(
                    $freshParent->state,
                    $stateDefinition,
                    $doneEvent,
                );
            } else {
                $freshParent->state->setInternalEventBehavior(
                    type: InternalEvent::CHILD_MACHINE_FAIL,
                    placeholder: $this->childMachineClass,
                );

                $failEvent = ChildMachineFailEvent::forChild([
                    'error_message' => $this->errorMessage ?? 'Unknown error',
                    'machine_id'    => $this->childRootEventId ?? '',
                    'machine_class' => $this->childMachineClass,
                    'child_context' => $this->childContextData,
                ]);

                $freshParent->definition->routeChildFailEvent(
                    $freshParent->state,
                    $stateDefinition,
                    $failEvent,
                );
            }

            // 6. Persist parent state
            $freshParent->persist();
        } finally {
            $lockHandle?->release();
        }
    }
}
