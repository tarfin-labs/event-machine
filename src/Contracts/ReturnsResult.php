<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Contracts;

/**
 * Interface for jobs that return a result to the parent machine.
 *
 * When a job actor implements this interface, its result() output
 * is passed to the parent via ChildMachineDoneEvent->output().
 * Jobs that do not implement this interface return an empty output.
 *
 * IMPORTANT: Job actors are simple service objects — NOT full queue jobs.
 * ChildJobJob calls $job->handle() directly, bypassing Laravel's queue
 * infrastructure (middleware, rate limiters, retry/backoff, ShouldBeUnique).
 * The job class must have a handle() method and optionally implement this
 * interface. Constructor parameters are resolved via app()->make($class, $data),
 * so parameter names must match the keys in the parent state's `with` config.
 */
interface ReturnsResult
{
    /**
     * Get the result data to be passed to the parent machine.
     *
     * @return array<string, mixed>
     */
    public function result(): array;
}
