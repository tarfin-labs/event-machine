<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

interface GuardBehavior
{
    public function __invoke(ContextDefinition $context, array $event): bool;
}
