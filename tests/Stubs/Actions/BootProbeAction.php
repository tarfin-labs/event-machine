<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Actions;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class BootProbeAction extends ActionBehavior
{
    public function __invoke(): void
    {
        // no-op boot-time entry action for spying() timing tests
    }
}
