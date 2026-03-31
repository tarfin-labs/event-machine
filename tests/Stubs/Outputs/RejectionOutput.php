<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Outputs;

use Tarfinlabs\EventMachine\Behavior\MachineOutput;

class RejectionOutput extends MachineOutput
{
    public function __construct(
        public readonly string $reason,
        public readonly string $reviewerId,
    ) {}
}
