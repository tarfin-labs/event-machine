<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Actions;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class ProbeTwoAction extends ActionBehavior
{
    public function __invoke(): void
    {
        // no-op probe target for spying() tests
    }
}
