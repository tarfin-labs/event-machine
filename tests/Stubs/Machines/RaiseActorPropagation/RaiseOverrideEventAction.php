<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\RaiseActorPropagation;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Raises an ActorOverrideEvent (which has actor() override returning 'overridden_actor').
 */
class RaiseOverrideEventAction extends ActionBehavior
{
    public function __invoke(): void
    {
        $this->raise(new ActorOverrideEvent());
    }
}
