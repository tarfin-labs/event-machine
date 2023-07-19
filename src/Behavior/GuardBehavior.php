<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\TransitionDefinition;
use Tarfinlabs\EventMachine\Exceptions\MissingMachineContextException;

abstract class GuardBehavior extends InvokableBehavior
{
    public array $requiredContext = [];
    public ?string $errorMessage  = null;

    abstract public function __invoke(
        ContextManager $context,
        EventBehavior $eventBehavior,
        array $arguments = null,
    ): bool;

    /**
     * Validates the required context for a given guard behavior and context manager.
     *
     * @param  callable|null  $guardBehavior The guard behavior to validate the required context for.
     * @param  ContextManager  $context The context manager to check for missing context.
     *
     * @throws MissingMachineContextException If missing context is detected.
     */
    public static function validateRequiredContext(?callable $guardBehavior, ContextManager $context): void
    {
        $hasMissingContext = TransitionDefinition::hasMissingContext($guardBehavior, $context);
        if ($hasMissingContext !== null) {
            throw MissingMachineContextException::build($hasMissingContext);
        }
    }
}
