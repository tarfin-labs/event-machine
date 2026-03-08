<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Entry action that sets context but does NOT call raise().
 * Region stays in its initial state forever — tests stuck machine behavior.
 */
class NoRaiseEntryAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('region_a_context_set', 'yes_but_no_raise');
        $context->set('region_a_pid', getmypid());
        // Deliberately: NO $this->raise() call
    }
}
