<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use ReflectionMethod;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionUnionType;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Mockery\ExpectationDirector;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Traits\Fakeable;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Routing\ForwardContext;
use Tarfinlabs\EventMachine\Enums\TransitionProperty;
use Tarfinlabs\EventMachine\Testing\InlineBehaviorFake;
use Tarfinlabs\EventMachine\Testing\CommunicationRecorder;
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
        if (CommunicationRecorder::isRecording()) {
            CommunicationRecorder::recordRaise($eventBehavior);
        }

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
        if (CommunicationRecorder::isRecording()) {
            CommunicationRecorder::recordSendTo($machineClass, $rootEventId, $event);

            return;
        }

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

            // For @always transitions, inject the original triggering event instead of the synthetic '@always' event
            $effectiveEvent = $eventBehavior;
            if ($eventBehavior instanceof EventBehavior
                && $eventBehavior->type === TransitionProperty::Always->value
                && $state->triggeringEvent instanceof EventBehavior) {
                $effectiveEvent = $state->triggeringEvent;
            }

            $value = match (true) {
                $typeName === null                                                                                                           => null,
                is_a($typeName, class: ForwardContext::class, allow_string: true)                                                            => $forwardContext,    // ForwardContext (child)
                is_a($typeName, class: ContextManager::class, allow_string: true) || is_subclass_of($typeName, class: ContextManager::class) => $state->context,    // ContextManager (parent)
                is_a($typeName, class: EventBehavior::class, allow_string: true) || is_subclass_of($typeName, class: EventBehavior::class)   => $effectiveEvent,    // EventBehavior (original event for @always)
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

        // Save raised events for assertRaised() assertions (per-class indexed)
        self::$raisedEventsPerClass[static::class] = $eventQueue;

        // For void actions (null return), return the eventQueue so raised events are accessible.
        return $result ?? $eventQueue;
    }

    // ─── Raised Event Assertions ─────────────────────────────

    /** @var array<class-string, Collection> Per-class raised events from last runWithState() call. */
    private static array $raisedEventsPerClass = [];

    /**
     * Assert that an event was raised during the last runWithState() call.
     *
     * Matches by event type string ('ORDER_SUBMITTED'), FQCN (OrderSubmittedEvent::class),
     * or instanceof check.
     */
    public static function assertRaised(string $eventTypeOrClass): void
    {
        $events = self::$raisedEventsPerClass[static::class] ?? null;

        Assert::assertNotNull(
            $events,
            'Cannot assert raised events: runWithState() has not been called on '.static::class.'.'
        );

        $found = $events->contains(fn (array $event): bool => self::matchesRaisedEvent($event, $eventTypeOrClass));

        Assert::assertTrue($found, "Expected event '{$eventTypeOrClass}' to be raised by ".static::class.' but it was not.');
    }

    /**
     * Assert that an event was NOT raised during the last runWithState() call.
     */
    public static function assertNotRaised(string $eventTypeOrClass): void
    {
        $events = self::$raisedEventsPerClass[static::class] ?? null;

        Assert::assertNotNull(
            $events,
            'Cannot assert raised events: runWithState() has not been called on '.static::class.'.'
        );

        $found = $events->contains(fn (array $event): bool => self::matchesRaisedEvent($event, $eventTypeOrClass));

        Assert::assertFalse($found, "Expected event '{$eventTypeOrClass}' NOT to be raised by ".static::class.' but it was.');
    }

    /**
     * Assert the exact number of events raised during the last runWithState() call.
     */
    public static function assertRaisedCount(int $expected): void
    {
        $events = self::$raisedEventsPerClass[static::class] ?? null;

        Assert::assertNotNull(
            $events,
            'Cannot assert raised events: runWithState() has not been called on '.static::class.'.'
        );

        Assert::assertCount($expected, $events, static::class.' raised '.$events->count()." event(s), expected {$expected}.");
    }

    /**
     * Assert that no events were raised during the last runWithState() call.
     */
    public static function assertNothingRaised(): void
    {
        $events = self::$raisedEventsPerClass[static::class] ?? null;

        Assert::assertNotNull(
            $events,
            'Cannot assert raised events: runWithState() has not been called on '.static::class.'.'
        );

        Assert::assertEmpty(
            $events,
            'Expected no events to be raised by '.static::class.' but '.$events->count().' were.'
        );
    }

    /**
     * Override Fakeable::resetAllFakes() to also clear raised events.
     */
    public static function resetAllFakes(): void
    {
        // Call the trait's resetAllFakes logic manually
        foreach (self::$fakes as $class => $mock) {
            app()->offsetUnset($class);

            foreach (array_keys($mock->mockery_getExpectations()) as $method) {
                $mock->mockery_setExpectationsFor(
                    $method,
                    new ExpectationDirector($method, $mock),
                );
            }
            $mock->mockery_teardown();
        }

        self::$fakes = [];
        self::$spies = [];

        InlineBehaviorFake::resetAll();

        // Clear raised events
        self::$raisedEventsPerClass = [];
    }

    /**
     * Check if a raised event matches the given type or class.
     */
    private static function matchesRaisedEvent(mixed $event, string $eventTypeOrClass): bool
    {
        // Match by instanceof
        if ($event instanceof $eventTypeOrClass) {
            return true;
        }

        // Match by event type string
        $type = is_object($event) ? ($event->type ?? null) : ($event['type'] ?? null);
        if ($type === $eventTypeOrClass) {
            return true;
        }

        // Match by FQCN → resolve to type via getType()
        if (class_exists($eventTypeOrClass) && method_exists($eventTypeOrClass, 'getType')) {
            return $type === $eventTypeOrClass::getType();
        }

        return false;
    }
}
