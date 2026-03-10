<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Routing;

use Illuminate\Http\JsonResponse;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Actor\Machine;

/**
 * Abstract base class for endpoint lifecycle hooks.
 *
 * Provides before/after/onException hooks that run in the HTTP layer
 * (MachineController), completely separate from machine internals.
 */
abstract class MachineEndpointAction
{
    protected Machine $machine;
    protected State $state;

    /**
     * Set the machine and state context.
     *
     * Called by the framework before lifecycle methods.
     */
    public function withMachineContext(Machine $machine, State $state): self
    {
        $this->machine = $machine;
        $this->state   = $state;

        return $this;
    }

    /**
     * Runs BEFORE $machine->send().
     *
     * Access:
     *   $this->machine : Machine (loaded)
     *   $this->state   : State   (pre-transition state)
     *
     * Use for: validation, authorization, acquiring locks.
     * Call abort() to stop the request.
     */
    public function before(): void {}

    /**
     * Runs AFTER $machine->send().
     *
     * Access:
     *   $this->machine : Machine (transition completed)
     *   $this->state   : State   (post-transition state)
     *
     * Use for: releasing locks, dispatching jobs.
     */
    public function after(): void {}

    /**
     * Runs when $machine->send() throws an exception.
     *
     * Return null to re-throw the exception.
     * Return a JsonResponse to handle it and use that response.
     *
     * Use for: lock release on failure, logging.
     */
    public function onException(\Throwable $e): ?JsonResponse
    {
        return null;
    }
}
