<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncParentMachine;

/**
 * Test stub action that calls dispatchTo() for async cross-machine communication testing.
 */
class DispatchToTargetAction extends ActionBehavior
{
    public function __invoke(ContextManager $ctx): void
    {
        $this->dispatchTo(
            machineClass: $ctx->get('target_class') ?? AsyncParentMachine::class,
            rootEventId: $ctx->get('target_root_event_id') ?? 'fake-root-event-id',
            event: ['type' => $ctx->get('event_type') ?? 'ASYNC_EVENT', 'payload' => []],
        );
    }
}
