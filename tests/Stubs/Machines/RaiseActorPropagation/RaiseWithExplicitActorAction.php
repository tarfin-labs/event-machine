<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\RaiseActorPropagation;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Raises an event WITH an explicit actor — tests that explicit wins over propagation.
 */
class RaiseWithExplicitActorAction extends ActionBehavior
{
    public function __invoke(): void
    {
        $this->raise(['type' => 'RAISED_EVENT', 'actor' => 'explicit_actor']);
    }
}
