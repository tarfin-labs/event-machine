<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Illuminate\Support\Collection;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\EventDefinition;

abstract class InvokableBehavior
{
    public function __construct(
        protected ?Collection $eventQueue = null
    ) {
        if ($this->eventQueue === null) {
            $this->eventQueue = new Collection();
        }
    }

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
    abstract public function __invoke(
        ContextManager $context,
        EventBehavior $eventBehavior,
        array $arguments = null,
    );

    /**
     * Raises an event by adding it to the event queue.
     *
     * @param  \Tarfinlabs\EventMachine\Definition\EventDefinition|array  $eventDefinition The event definition object to be raised.
     */
    public function raise(EventDefinition|array $eventDefinition): void
    {
        $this->eventQueue->push($eventDefinition);
    }
}
