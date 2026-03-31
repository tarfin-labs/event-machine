<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Jobs;

/**
 * Job that implements neither ReturnsOutput nor ProvidesFailure — tests raw behavior.
 */
class NoInterfaceJob
{
    public function handle(): void {}
}
