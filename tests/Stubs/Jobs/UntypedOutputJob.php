<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Jobs;

use Tarfinlabs\EventMachine\Contracts\ReturnsOutput;

/**
 * Job returning untyped array output — tests backward compat.
 */
class UntypedOutputJob implements ReturnsOutput
{
    public function handle(): void {}

    public function output(): array
    {
        return ['done' => true, 'message' => 'completed'];
    }
}
