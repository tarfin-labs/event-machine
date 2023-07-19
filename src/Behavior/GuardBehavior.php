<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Tarfinlabs\EventMachine\ContextManager;

abstract class GuardBehavior extends InvokableBehavior
{
    public array $requiredContext = [];
    public ?string $errorMessage  = null;

    abstract public function __invoke(
        ContextManager $context,
        EventBehavior $eventBehavior,
        array $arguments = null,
    ): bool;
}
