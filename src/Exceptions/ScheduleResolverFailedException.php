<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use Throwable;
use RuntimeException;

/**
 * A schedule's resolver threw while selecting the instances to dispatch to.
 *
 * It exists to keep "the resolver blew up" distinguishable from "nothing to process". Both
 * used to produce an empty instance list, so `machine:process-scheduled` printed "No matching
 * instances found" and exited SUCCESS — a cron monitor stayed green while nothing was
 * dispatched, which is the failure mode a scheduled command most needs to not have.
 */
class ScheduleResolverFailedException extends RuntimeException
{
    public static function forResolver(string $machineClass, Throwable $previous): self
    {
        return new self(
            message: "Schedule resolver for {$machineClass} failed: {$previous->getMessage()}",
            previous: $previous,
        );
    }
}
