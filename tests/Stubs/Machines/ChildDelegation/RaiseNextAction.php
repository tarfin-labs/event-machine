<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Raises a NEXT event during entry.
 */
class RaiseNextAction extends ActionBehavior
{
    public function __invoke(): void
    {
        $this->raise([
            'type' => 'NEXT',
        ]);
    }
}
