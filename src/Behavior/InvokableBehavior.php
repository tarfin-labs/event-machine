<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Exceptions\MissingMachineContextException;

abstract class InvokableBehavior
{
    public array $requiredContext = [];

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
     * @param  \Tarfinlabs\EventMachine\Behavior\EventBehavior|array  $eventBehavior The event definition object to be raised.
     */
    public function raise(EventBehavior|array $eventBehavior): void
    {
        $this->eventQueue->push($eventBehavior);
    }

    /**
     * Checks if the given context has any missing attributes.
     *
     * This method checks if the required context attributes specified in the
     * "$requiredContext" property are present in the given context. It returns
     * the key of the first missing attribute if any, otherwise it returns null.
     *
     * @param  ContextManager  $context The context to be checked.
     *
     * @return string|null  The key of the first missing attribute, or null if all
     *                      required attributes are present.
     */
    public function hasMissingContext(ContextManager $context): ?string
    {
        // Check if the requiredContext property is an empty array
        if (empty($this->requiredContext)) {
            return null;
        }

        // Iterate through the required context attributes
        /* @var GuardBehavior $guardBehavior */
        foreach ($this->requiredContext as $key => $type) {
            // Check if the context manager has the required context attribute
            if (!$context->has($key, $type)) {
                // Return the key of the missing context attribute
                return $key;
            }
        }

        // Return null if all the required context attributes are present
        return null;
    }

    /**
     * Validates the required context for the behavior.
     *
     * This method checks if all the required context properties are present
     * in the given ContextManager instance. If any required context property is missing,
     * it throws a MissingMachineContextException.
     *
     * @param  ContextManager  $context The context to be validated.
     *
     * @throws MissingMachineContextException If any required context property is missing.
     */
    public function validateRequiredContext(ContextManager $context): void
    {
        $missingContext = $this->hasMissingContext($context);

        if ($missingContext !== null) {
            throw MissingMachineContextException::build($missingContext);
        }
    }

    /**
     * Get the type of the current InvokableBehavior.
     *
     * This method returns the type of the InvokableBehavior as a string.
     * The type is determined by converting the FQCN of the
     * InvokableBehavior to base class name as camel case.
     *
     * @return string The type of the behavior.
     */
    public static function getType(): string
    {
        return Str::of(static::class)
            ->classBasename()
            ->toString();
    }
}
