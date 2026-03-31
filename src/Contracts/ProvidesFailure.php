<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Contracts;

use Tarfinlabs\EventMachine\Behavior\MachineFailure;

/**
 * Interface for jobs that provide typed failure data.
 *
 * When a job actor implements this interface, its failure() output
 * is passed to the parent via ChildMachineFailEvent.
 * The parent's @fail action can type-hint the MachineFailure subclass
 * for IDE autocomplete and type safety.
 *
 * The method is static to avoid re-instantiating the job in the failed() callback.
 *
 * @see ReturnsOutput for the success-path equivalent
 */
interface ProvidesFailure
{
    /**
     * Map an exception to a typed failure object.
     *
     * This data becomes available for typed injection in parent @fail actions.
     */
    public static function failure(\Throwable $exception): MachineFailure;
}
