<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Actions;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class SleepAction extends ActionBehavior
{
    public function __invoke(): void
    {
        sleep(1);
    }
}
