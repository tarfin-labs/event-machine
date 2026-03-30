<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Failures;

use Tarfinlabs\EventMachine\Behavior\MachineFailure;

/**
 * Tests sensible default: only $message, no override needed.
 */
class SimpleFailure extends MachineFailure
{
    public function __construct(
        public readonly string $message,
    ) {}
}
