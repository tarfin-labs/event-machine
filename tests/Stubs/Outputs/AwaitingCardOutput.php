<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Outputs;

use Tarfinlabs\EventMachine\Behavior\MachineOutput;

class AwaitingCardOutput extends MachineOutput
{
    public function __construct(
        public readonly string $status,
    ) {}
}
