<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Tarfinlabs\EventMachine\Behavior\MachineOutput;
use Tarfinlabs\EventMachine\Contracts\ReturnsOutput;
use Tarfinlabs\EventMachine\Contracts\ProvidesFailure;

/**
 * Queue job that runs a Laravel job as an actor.
 *
 * Dispatched when a parent state with `job` key is entered.
 * Creates the job via app()->make(), calls handle() directly, and dispatches
 * ChildMachineCompletionJob to route @done/@fail back to the parent.
 *
 * NOTE: The inner job's handle() is called directly — this intentionally bypasses
 * Laravel's queue middleware, rate limiters, retry/backoff, and ShouldBeUnique.
 * Inner jobs should be simple service objects, not full queue jobs.
 * See ReturnsOutput contract for the full contract documentation.
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
        // 1. Validate that the job class exists and has a handle() method
        if (!class_exists($this->jobClass)) {
            throw new \InvalidArgumentException("Job class '{$this->jobClass}' does not exist.");
        }

        if (!method_exists($this->jobClass, 'handle')) {
            throw new \InvalidArgumentException("Job class '{$this->jobClass}' must have a handle() method.");
        }

        // 2. Create and run the job (use app()->call() for dependency injection)
        $job = app()->make($this->jobClass, $this->jobData);
        app()->call([$job, 'handle']);

        // 2. Fire-and-forget: no completion needed
        if ($this->fireAndForget) {
            return;
        }

        // 3. Extract output if job implements ReturnsOutput
        $output      = $job instanceof ReturnsOutput ? $job->output() : [];
        $outputData  = $output instanceof MachineOutput ? $output->toArray() : $output;
        $outputClass = $output instanceof MachineOutput ? $output::class : null;

        // 4. Dispatch completion to parent
        dispatch(new ChildMachineCompletionJob(
            parentRootEventId: $this->parentRootEventId,
            parentMachineClass: $this->parentMachineClass,
            parentStateId: $this->parentStateId,
            childMachineClass: $this->jobClass,
            childRootEventId: null,
            success: true,
            childContextData: [],
            outputData: $outputData,
            outputClass: $outputClass,
        ));
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->fireAndForget) {
            return;
        }

        // Extract typed failure if the job supports it
        $output       = null;
        $failureClass = null;

        if (is_a($this->jobClass, ProvidesFailure::class, true)) {
            try {
                $failure      = $this->jobClass::failure($exception);
                $output       = $failure->toArray();
                $failureClass = $failure::class;
            } catch (\Throwable) {
                // failure() itself failed — proceed with null output
            }
        }

        dispatch(new ChildMachineCompletionJob(
            parentRootEventId: $this->parentRootEventId,
            parentMachineClass: $this->parentMachineClass,
            parentStateId: $this->parentStateId,
            childMachineClass: $this->jobClass,
            childRootEventId: null,
            success: false,
            errorMessage: $exception->getMessage(),
            errorCode: $exception->getCode(),
            outputData: $output,
            failureClass: $failureClass,
        ));
    }
}
