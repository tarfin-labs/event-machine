<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

class StateDefinition
{
    /** The root machine definition this state definition belongs to. */
    public MachineDefinition $machine;

    /** The local id of the state definition, representing its location in the overall state value. */
    public ?string $localId;

    /**
     * Create a new state definition with the given configuration and options.
     *
     * @param  ?array  $config The raw configuration array used to create the state definition.
     * @param  ?array  $options The options array for configuring the state definition.
     */
    public function __construct(
        public ?array $config,
        ?array $options = null,
    ) {
        $this->machine = $options['machine'] ?? null;
        $this->localId = $options['local_id'] ?? null;
    }
}
