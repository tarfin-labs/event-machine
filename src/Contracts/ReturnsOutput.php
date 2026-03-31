<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Contracts;

use Tarfinlabs\EventMachine\Behavior\MachineOutput;

/**
 * Interface for jobs that return output to the parent machine.
 *
 * When a job actor implements this interface, its output() value
 * is passed to the parent via ChildMachineDoneEvent->output().
 * Jobs that do not implement this interface return an empty output.
 *
 * Returns array for simple untyped output, or MachineOutput for typed contracts.
 */
interface ReturnsOutput
{
    /**
     * Get the output data to be passed to the parent machine.
     *
     * @return array<string, mixed>|MachineOutput
     */
    public function output(): array|MachineOutput;
}
