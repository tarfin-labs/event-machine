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
            machineClass: $ctx->get('targetClass') ?? AsyncParentMachine::class,
            rootEventId: $ctx->get('targetRootEventId') ?? 'fake-root-event-id',
            event: ['type' => $ctx->get('eventType') ?? 'ASYNC_EVENT', 'payload' => []],
        );
    }
}
