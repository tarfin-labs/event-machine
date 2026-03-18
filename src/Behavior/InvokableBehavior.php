<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use ReflectionMethod;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionUnionType;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Traits\Fakeable;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Routing\ForwardContext;
use Tarfinlabs\EventMachine\Exceptions\MissingMachineContextException;

/**
 * The abstract class InvokableBehavior defines the common behavior
 * for classes that can be invoked directly. It provides methods for
 * executing the behavior, raising events, checking and validating
 * required context attributes, and getting the type of the behavior.
 */
abstract class InvokableBehavior
{
    use Fakeable;

    /** @var array<string> An array containing the required context and the type of the context for the code to execute correctly. */
    public static array $requiredContext = [];

    /** @var bool Write log in console */
    public bool $shouldLog = false;

    /**
     * Constructs a new instance of the class.
     *
     * @param  Collection|null  $eventQueue  The event queue collection. Default is null.
     */
    public function __construct(protected ?Collection $eventQueue = null)
    {
        if (!$this->eventQueue instanceof Collection) {
            $this->eventQueue = new Collection();
        }
    }

    /**
     * Raises an event by adding it to the event queue.
     *
     * @param  EventBehavior|array  $eventBehavior  The event definition object to be raised.
     */
    public function raise(EventBehavior|array $eventBehavior): void
    {
        $this->eventQueue->push($eventBehavior);
    }

    /**
     * Send an event synchronously to another machine by its root_event_id.
     *
     * Restores the target machine and calls send() directly (blocking).
     *
     * @param  string  $machineClass  The FQCN of the target Machine subclass.
     * @param  string  $rootEventId  The target machine's root_event_id.
     * @param  EventBehavior|array  $event  The event to send.
     */
    public function sendTo(string $machineClass, string $rootEventId, EventBehavior|array $event): void
    {
        /** @var Machine $targetMachine */
        $targetMachine                           = $machineClass::withDefinition($machineClass::definition());
        $targetMachine->definition->machineClass = $machineClass;
        $targetMachine->start($rootEventId);
        $targetMachine->send($event);
    }

    /**
     * Dispatch an event asynchronously to another machine via queue.
     *
     * Dispatches a SendToMachineJob to deliver the event on the queue.
     *
     * @param  string  $machineClass  The FQCN of the target Machine subclass.
     * @param  string  $rootEventId  The target machine's root_event_id.
     * @param  EventBehavior|array  $event  The event to send.
     */
    public function dispatchTo(string $machineClass, string $rootEventId, EventBehavior|array $event): void
    {
        $eventArray = $event instanceof EventBehavior
            ? ['type' => $event->type, 'payload' => $event->payload]
            : $event;

        dispatch(new SendToMachineJob(
            machineClass: $machineClass,
            rootEventId: $rootEventId,
            event: $eventArray,
        ));
    }

    /**
     * Send an event synchronously to the parent machine that invoked this child.
     *
     * Shorthand for sendTo(parentMachineClass, parentMachineId, event).
     * Throws if called on a machine that was not invoked by a parent.
     *
     * @param  ContextManager  $context  The child machine's context (contains parent identity).
     * @param  EventBehavior|array  $event  The event to send to the parent.
     *
     * @throws \RuntimeException If this machine has no parent.
     */
    public function sendToParent(ContextManager $context, EventBehavior|array $event): void
    {
        $parentRootEventId  = $context->parentMachineId();
        $parentMachineClass = $context->parentMachineClass();

        if ($parentRootEventId === null || $parentMachineClass === null) {
            throw new \RuntimeException('Cannot sendToParent: this machine was not invoked by a parent.');
        }

        $this->sendTo(
            machineClass: $parentMachineClass,
            rootEventId: $parentRootEventId,
            event: $event,
        );
    }

    /**
     * Dispatch an event asynchronously to the parent machine via queue.
     *
     * Shorthand for dispatchTo(parentMachineClass, parentMachineId, event).
     * Throws if called on a machine that was not invoked by a parent.
     *
     * @param  ContextManager  $context  The child machine's context (contains parent identity).
     * @param  EventBehavior|array  $event  The event to send to the parent.
     *
     * @throws \RuntimeException If this machine has no parent.
     */
    public function dispatchToParent(ContextManager $context, EventBehavior|array $event): void
    {
        $parentRootEventId  = $context->parentMachineId();
        $parentMachineClass = $context->parentMachineClass();

        if ($parentRootEventId === null || $parentMachineClass === null) {
            throw new \RuntimeException('Cannot dispatchToParent: this machine was not invoked by a parent.');
        }

        $this->dispatchTo(
            machineClass: $parentMachineClass,
            rootEventId: $parentRootEventId,
            event: $event,
        );
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
    public static function hasMissingContext(ContextManager $context): ?string
    {
        // Check if the requiredContext property is an empty array
        if (static::$requiredContext === []) {
            return null;
        }

        // Iterate through the required context attributes
        /* @var GuardBehavior $guardBehavior */
        foreach (static::$requiredContext as $key => $type) {
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
    public static function validateRequiredContext(ContextManager $context): void
    {
        $missingContext = static::hasMissingContext($context);

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
        ?ForwardContext $forwardContext = null,
    ): array {
        $invocableBehaviorParameters = [];

        $invocableBehaviorReflection = $actionBehavior instanceof self
            ? new ReflectionMethod($actionBehavior, '__invoke')
            : new ReflectionFunction($actionBehavior);

        foreach ($invocableBehaviorReflection->getParameters() as $parameter) {
            $parameterType = $parameter->getType();

            $typeName = match (true) {
                $parameterType instanceof ReflectionUnionType => $parameterType->getTypes()[0]->getName(),
                $parameterType instanceof ReflectionNamedType => $parameterType->getName(),
                default                                       => null,
            };

            $value = match (true) {
                $typeName === null                                                                                                           => null,
                is_a($typeName, class: ForwardContext::class, allow_string: true)                                                            => $forwardContext,    // ForwardContext (child)
                is_a($typeName, class: ContextManager::class, allow_string: true) || is_subclass_of($typeName, class: ContextManager::class) => $state->context,    // ContextManager (parent)
                is_a($typeName, class: EventBehavior::class, allow_string: true) || is_subclass_of($typeName, class: EventBehavior::class)   => $eventBehavior,     // EventBehavior
                $state instanceof $typeName                                                                                                  => $state,             // State
                is_a($state->history, $typeName)                                                                                             => $state->history,    // EventCollection
                $typeName === 'array'                                                                                                        => $actionArguments,   // Behavior Arguments
                default                                                                                                                      => null,
            };

            $invocableBehaviorParameters[] = $value;
        }

        return $invocableBehaviorParameters;
    }

    /**
     * Run the behavior with the given arguments.
     *
     * @param  mixed  ...$args  Arguments to pass to the behavior
     */
    public static function run(mixed ...$args): mixed
    {
        return App::make(static::class)(...$args);
    }

    /**
     * Run the behavior using the engine's exact parameter injection logic.
     *
     * Resolves through container (supporting constructor DI and fakes),
     * then uses injectInvokableBehaviorParameters to match the exact
     * parameter order the engine would provide at runtime.
     *
     * @param  State  $state  The state to run against.
     * @param  EventBehavior|null  $eventBehavior  Optional event behavior.
     * @param  array|null  $arguments  Optional behavior arguments.
     *
     * @return bool|Collection<int, mixed>|mixed Guards return bool, calculators
     *                                           mutate context and return void (→ eventQueue Collection), actions return
     *                                           void (→ eventQueue Collection). The eventQueue captures any events raised
     *                                           via $this->raise() during execution.
     */
    public static function runWithState(
        State $state,
        ?EventBehavior $eventBehavior = null,
        ?array $arguments = null,
    ): mixed {
        $eventQueue = new Collection();
        $instance   = App::make(static::class, ['eventQueue' => $eventQueue]);

        $params = static::injectInvokableBehaviorParameters(
            actionBehavior: $instance,
            state: $state,
            eventBehavior: $eventBehavior,
            actionArguments: $arguments,
        );

        $result = $instance(...$params);

        // For void actions (null return), return the eventQueue so raised events are accessible.
        return $result ?? $eventQueue;
    }
}
