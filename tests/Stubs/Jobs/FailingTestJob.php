<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Jobs;

/**
 * A test job actor that always throws an exception.
 */
class FailingTestJob
{
    public function handle(): void
    {
        throw new \RuntimeException('Job actor failed: simulated error');
    }
}
