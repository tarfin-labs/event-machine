<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Tarfinlabs\EventMachine\ContextManager;

/**
 * Class GuardBehavior.
 *
 * This is an abstract class that extends InvokableBehavior and provides the base structure for guard behavior classes.
 * Guards are used in event-driven systems to determine whether an event should be allowed to proceed or not.
 */
abstract class GuardBehavior extends InvokableBehavior
{
    /**
     * Invokes the method.
     *
     * @param  ContextManager  $context The context manager.
     * @param  EventBehavior  $eventBehavior The event behavior.
     * @param  array|null  $arguments The optional arguments for the method.
     *
     * @return bool Returns true if the method is invoked successfully, false otherwise.
     */
    abstract public function __invoke(
        ContextManager $context,
        EventBehavior $eventBehavior,
        array $arguments = null,
    ): bool;
}
