<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Xyz\Actions;

use Closure;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class XAction extends ActionBehavior
{
    public function definition(): Closure
    {
        return function (ContextManager $context): void {
            $context->value .= 'x';

            $this->raise(['type' => '@x']);
        };
    }
}
