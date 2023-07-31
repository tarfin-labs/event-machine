<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Tarfinlabs\EventMachine\ContextManager;

/**
 * An abstract class that represents a validation guard behavior.
 *
 * This class extends the GuardBehavior class and provides a basic structure for
 * implementing validation guard behaviors. It defines a public property `$errorMessage`
 * that can be used to store an error message and an abstract function `__invoke` that
 * must be implemented by the child classes.
 */
abstract class ValidationGuardBehavior extends GuardBehavior
{
    /** @var string|null Holds an error message, which is initially null. */
    public ?string $errorMessage = null;

    /**
     * Invokes the method.
     *
     * @param  ContextManager  $context The context manager.
     * @param  EventBehavior  $eventBehavior The event behavior.
     * @param  array|null  $arguments The arguments for the method (optional).
     *
     * @return bool Returns true if the method invocation was successful, false otherwise.
     */
    abstract public function __invoke(
        ContextManager $context,
        EventBehavior $eventBehavior,
        array $arguments = null,
    ): bool;
}
