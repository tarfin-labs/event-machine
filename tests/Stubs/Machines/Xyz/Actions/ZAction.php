<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Xyz\Actions;

use Closure;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class ZAction extends ActionBehavior
{
    public function definition(): Closure
    {
        return function (ContextManager $context): void {
            $context->value .= 'z';

            $this->raise(['type' => '@z']);
        };
    }
}
