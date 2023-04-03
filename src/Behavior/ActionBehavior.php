<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\EventDefinition;

interface ActionBehavior extends InvokableBehavior
{
    public function __invoke(ContextManager $context, EventDefinition $eventDefinition): void;
}
