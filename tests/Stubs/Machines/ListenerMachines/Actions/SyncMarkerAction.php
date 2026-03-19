<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ListenerMachines\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * A sync listener action that writes a marker to context.
 * Used by LocalQA tests to verify sync listeners run inline.
 */
class SyncMarkerAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('sync_listener_ran', true);
    }
}
