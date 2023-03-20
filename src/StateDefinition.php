<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

class StateDefinition
{
    /** The root machine definition. */
    public MachineDefinition $machine;

    public string $localId;

    public function __construct(
        public ?array $config,
        ?array $options = null,
    ) {
        $this->machine = $options['machine'] ?? null;
        $this->localId = $options['local_id'] ?? null;
    }
}
