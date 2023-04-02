<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

interface ActionBehavior
{
    public function __invoke(ContextDefinition $context, array $event): void;
}
