<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Tarfinlabs\EventMachine\ContextManager;

/**
 * ActionBehavior class.
 *
 * This abstract class extends the InvokableBehavior class. It provides a way to define action behaviors
 * that can be invoked within a specific context.
 */
abstract class ActionBehavior extends InvokableBehavior
{
    /**
     * Invokes the method with the given parameters.
     *
     * @param  ContextManager  $context Provides access to the context in which the method is being invoked.
     * @param  EventBehavior  $eventBehavior The event behavior associated with the method invocation.
     * @param  array|null  $arguments Optional parameters to be passed to the method.
     */
    abstract public function __invoke(
        ContextManager $context,
        EventBehavior $eventBehavior,
        ?array $arguments = null,
    ): void;
}
