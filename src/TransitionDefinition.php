<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

class TransitionDefinition
{
    /**
     * @param  \Tarfinlabs\EventMachine\StateDefinition  $source
     * @param  array<\Tarfinlabs\EventMachine\StateDefinition>|null  $target
     */
    public function __construct(
        public StateDefinition $source,
        public string $event,
        public ?array $target = null,
        public ?array $actions = [],
    ) {
    }
}
