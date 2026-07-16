<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Listeners;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Queued entry listener action that counts how many times it ran.
 */
class CountingEntryAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('listenerRuns', ((int) $context->get('listenerRuns')) + 1);
    }
}
