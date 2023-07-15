<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Tarfinlabs\EventMachine\ContextManager;

interface InvokableBehavior
{
    /**
     * Executes the behavior with the given context and event.
     *
     * This method defines the contract for implementing behaviors
     * within classes. The behavior should be directly invokable by
     * passing in a ContextManager instance and an array of event payload.
     *
     * @param  ContextManager  $context The context to be used during
     *                                                                        invocation.
     * @param  \Tarfinlabs\EventMachine\Behavior\EventBehavior  $eventBehavior The event related to the
     *                                                                        current behavior.
     * @param  array|null  $arguments The arguments to be passed to the behavior.
     *
     * @phpstan-ignore-next-line
     */
    public function __invoke(
        ContextManager $context,
        EventBehavior $eventBehavior,
        array $arguments = null,
    );
}
