<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Qwerty\Actions;

use Closure;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Qwerty\Events\TEvent;

class TAction extends ActionBehavior
{
    public function definition(): Closure
    {
        return function (
            ContextManager $context,
            EventBehavior $eventBehavior
        ): void {
            $this->raise(new TEvent(actor: $eventBehavior->actor($context)));
        };
    }
}
