<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Contracts;

/**
 * Interface for jobs that return a result to the parent machine.
 *
 * When a job actor implements this interface, its result() output
 * is passed to the parent via ChildMachineDoneEvent->output().
 * Jobs that do not implement this interface return an empty output.
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
