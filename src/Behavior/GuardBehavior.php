<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Tarfinlabs\EventMachine\ContextManager;

interface GuardBehavior
{
    public function __invoke(ContextManager $context, array $event): bool;
}
