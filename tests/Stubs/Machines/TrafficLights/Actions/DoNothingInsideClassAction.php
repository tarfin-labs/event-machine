<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions;

use Closure;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class DoNothingInsideClassAction extends ActionBehavior
{
    public function definition(): Closure
    {
        return function (): void {
        };
    }
}
