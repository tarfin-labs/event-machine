<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Outputs;

use Tarfinlabs\EventMachine\Behavior\MachineOutput;

class ApprovalOutput extends MachineOutput
{
    public function __construct(
        public readonly string $approvalId,
        public readonly string $approvedBy,
    ) {}
}
