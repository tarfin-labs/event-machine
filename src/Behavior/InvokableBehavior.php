<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use ReflectionMethod;
use ReflectionFunction;
use ReflectionUnionType;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Exceptions\MissingMachineContextException;

/**
 * The abstract class InvokableBehavior defines the common behavior
 * for classes that can be invoked directly. It provides methods for
 * executing the behavior, raising events, checking and validating
 * required context attributes, and getting the type of the behavior.
 */
abstract class InvokableBehavior
{
    /** @var array<string> An array containing the required context and the type of the context for the code to execute correctly. */
    public array $requiredContext = [];

    /** @var bool Write log in console */
    public bool $shouldLog = false;

    /**
     * Constructs a new instance of the class.
     *
     * @param  Collection|null  $eventQueue  The event queue collection. Default is null.
     *
     * @return void
     */
    public function __construct(protected ?Collection $eventQueue = null)
    {
        if ($this->eventQueue === null) {
            $this->eventQueue = new Collection;
        }
    }

    /**
     * Raises an event by adding it to the event queue.
     *
     * @param  \Tarfinlabs\EventMachine\Behavior\EventBehavior|array  $eventBehavior  The event definition object to be raised.
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
     * @param  ContextManager  $context  The context to be checked.
     *
     * @return string|null The key of the first missing attribute, or null if all
     *                     required attributes are present.
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
     * @param  ContextManager  $context  The context to be validated.
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

    /**
     * Injects invokable behavior parameters.
     *
     * Retrieves the parameters of the given invokable behavior and injects the corresponding values
     * based on the provided state, event behavior, and action arguments.
     * The injected values are added to an array and returned.
     *
     * @param  callable  $actionBehavior  The invokable behavior to inject parameters for.
     * @param  State  $state  The state object used for parameter matching.
     * @param  EventBehavior|null  $eventBehavior  The event behavior used for parameter matching. (Optional)
     * @param  array|null  $actionArguments  The action arguments used for parameter matching. (Optional)
     *
     * @return array The injected invokable behavior parameters.
     *
     * @throws \ReflectionException
     */
    public static function injectInvokableBehaviorParameters(
        callable $actionBehavior,
        State $state,
        ?EventBehavior $eventBehavior = null,
        ?array $actionArguments = null,
    ): array {
        $invocableBehaviorParameters = [];

        $invocableBehaviorReflection = $actionBehavior instanceof self
            ? new ReflectionMethod($actionBehavior, '__invoke')
            : new ReflectionFunction($actionBehavior);

        foreach ($invocableBehaviorReflection->getParameters() as $parameter) {
            $parameterType = $parameter->getType();

            $typeName = $parameterType instanceof ReflectionUnionType
                ? $parameterType->getTypes()[0]->getName()
                : $parameterType->getName();

            $value = match (true) {
                is_a($state->context, $typeName) => $state->context,    // ContextManager
                is_a($eventBehavior, $typeName)  => $eventBehavior,     // EventBehavior
                is_a($state, $typeName)          => $state,             // State
                is_a($state->history, $typeName) => $state->history,    // EventCollection
                $typeName === 'array'            => $actionArguments,   // Behavior Arguments
                default                          => null,
            };

            $invocableBehaviorParameters[] = $value;
        }

        return $invocableBehaviorParameters;
    }

    /**
     * This method is used to create an instance of the invoking class.
     */
    public static function make(): mixed
    {
        return app(static::class);
    }

    /**
     * This method creates an instance of the invoking class and calls it as a callable, passing any provided arguments.
     *
     * @param  mixed  ...$arguments
     */
    public static function run(...$arguments): mixed
    {
        return static::make()(...$arguments);
    }
}
