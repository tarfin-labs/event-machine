<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Actions;

use Closure;
use RuntimeException;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class DAction extends ActionBehavior
{
    public function definition(): Closure
    {
        return function (): void {
            throw new RuntimeException('error');
        };
    }
}
