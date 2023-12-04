<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Actions;

use Closure;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class SleepAction extends ActionBehavior
{
    public function definition(): Closure
    {
        return function (): void {
            sleep(1);
        };
    }
}
