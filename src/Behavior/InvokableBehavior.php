<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\EventDefinition;

interface InvokableBehavior
{
    /**
     * Executes the behavior with the given context and event.
     *
     * This method defines the contract for implementing behaviors
     * within classes. The behavior should be directly invokable by
     * passing in a ContextManager instance and an array of event data.
     *
     * @param  ContextManager  $context          The context to be used during
     *                                                                        invocation.
     * @param  \Tarfinlabs\EventMachine\Definition\EventDefinition  $eventDefinition  The event data related to the
     *                                                                        current behavior.
     *
     * @phpstan-ignore-next-line
     */
    public function __invoke(ContextManager $context, EventDefinition $eventDefinition);
}
