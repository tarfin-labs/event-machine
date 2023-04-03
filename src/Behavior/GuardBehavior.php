<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Tarfinlabs\EventMachine\ContextDefinition;

interface GuardBehavior
{
    public function __invoke(ContextDefinition $context, array $event): bool;
}
