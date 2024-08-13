<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class DoNothingInsideClassAction extends ActionBehavior
{
    public function __invoke(): void
    {
    }
}
