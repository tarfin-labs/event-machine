<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Tarfinlabs\EventMachine\ContextManager;

interface ActionBehavior
{
    public function __invoke(ContextManager $context, array $event): void;
}
