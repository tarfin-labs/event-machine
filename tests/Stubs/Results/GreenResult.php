<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Results;

use Illuminate\Support\Carbon;
use Tarfinlabs\EventMachine\Behavior\OutputBehavior;

class GreenResult extends OutputBehavior
{
    public function __invoke(): Carbon
    {
        return now();
    }
}
