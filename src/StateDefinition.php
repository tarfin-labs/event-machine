<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

class StateDefinition
{
    /** The root machine definition. */
    public MachineDefinition $machine;

    public function __construct(
        public ?array $config,
        ?array $options = null,
    ) {
        $this->machine = $options['machine'] ?? null;
    }
}
