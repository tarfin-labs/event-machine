<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScheduledMachines;

use Illuminate\Support\Collection;
use Tarfinlabs\EventMachine\Contracts\ScheduleResolver;

/**
 * Stub resolver that returns a configurable list of root_event_ids.
 */
class ExpiredApplicationsResolver implements ScheduleResolver
{
    /** @var Collection<int, string> */
    public static Collection $ids;

    public static function setUp(array $ids): void
    {
        static::$ids = collect($ids);
    }

    public function __invoke(): Collection
    {
        return static::$ids ?? collect();
    }
}
