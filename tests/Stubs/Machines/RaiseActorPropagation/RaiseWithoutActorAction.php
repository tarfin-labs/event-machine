<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\RaiseActorPropagation;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Raises an event WITHOUT explicit actor — tests auto-propagation.
 */
class RaiseWithoutActorAction extends ActionBehavior
{
    public function __invoke(): void
    {
        $this->raise(['type' => 'RAISED_EVENT']);
    }
}
