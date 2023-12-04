<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Results;

use Closure;
use Illuminate\Support\Carbon;
use Tarfinlabs\EventMachine\Behavior\ResultBehavior;

class GreenResult extends ResultBehavior
{
    public function definition(): Closure
    {
        return function (): Carbon {
            return now();
        };
    }
}
