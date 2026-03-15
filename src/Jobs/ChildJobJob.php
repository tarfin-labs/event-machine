<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Tarfinlabs\EventMachine\Contracts\ReturnsResult;

/**
 * Queue job that runs a Laravel job as an actor.
 *
 * Dispatched when a parent state with `job` key is entered.
 * Creates the job, runs it, and dispatches ChildMachineCompletionJob
 * to route @done/@fail back to the parent.
 */
class ChildJobJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  string  $parentRootEventId  The parent machine's root_event_id.
     * @param  string  $parentMachineClass  FQCN of the parent machine.
     * @param  string  $parentStateId  The parent state that invoked the job.
     * @param  string  $jobClass  FQCN of the job to run.
     * @param  array  $jobData  Data to pass to the job constructor.
     * @param  bool  $fireAndForget  Whether this is a fire-and-forget job (no @done).
     * @param  string|null  $machineChildId  Tracking record ID.
     */
    public function __construct(
        public readonly string $parentRootEventId,
        public readonly string $parentMachineClass,
        public readonly string $parentStateId,
        public readonly string $jobClass,
        public readonly array $jobData = [],
        public readonly bool $fireAndForget = false,
        public readonly ?string $machineChildId = null,
    ) {}

    public function handle(): void
    {
        // 1. Create and run the job
        $job = app()->make($this->jobClass, $this->jobData);
        $job->handle();

        // 2. Fire-and-forget: no completion needed
        if ($this->fireAndForget) {
            return;
        }

        // 3. Extract result if job implements ReturnsResult
        $result = $job instanceof ReturnsResult ? $job->result() : [];

        // 4. Dispatch completion to parent
        dispatch(new ChildMachineCompletionJob(
            parentRootEventId: $this->parentRootEventId,
            parentMachineClass: $this->parentMachineClass,
            parentStateId: $this->parentStateId,
            childMachineClass: $this->jobClass,
            childRootEventId: null,
            success: true,
            result: null,
            childContextData: [],
            outputData: $result,
        ));
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->fireAndForget) {
            return;
        }

        dispatch(new ChildMachineCompletionJob(
            parentRootEventId: $this->parentRootEventId,
            parentMachineClass: $this->parentMachineClass,
            parentStateId: $this->parentStateId,
            childMachineClass: $this->jobClass,
            childRootEventId: null,
            success: false,
            errorMessage: $exception->getMessage(),
        ));
    }
}
