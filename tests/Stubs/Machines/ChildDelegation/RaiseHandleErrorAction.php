<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Raises a HANDLE_ERROR event during entry.
 */
class RaiseHandleErrorAction extends ActionBehavior
{
    public function __invoke(): void
    {
        $this->raise([
            'type' => 'HANDLE_ERROR',
        ]);
    }
}
