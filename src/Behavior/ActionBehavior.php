<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Tarfinlabs\EventMachine\Definition\ContextDefinition;

interface ActionBehavior
{
    public function __invoke(ContextDefinition $context, array $event): void;
}
