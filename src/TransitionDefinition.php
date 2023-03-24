<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

class TransitionDefinition
{
    public ?StateDefinition $target;

    public function __construct(
        public null|string|array $config,
        public StateDefinition $source,
        public string $event,
    ) {
        if ($this->config === null) {
            $this->target = null;
        }

        if (is_string($this->config)) {
            $this->target = $this->source->parent->states[$this->config];
        }

        if (is_array($this->config)) {
            $this->target = $this->config['target'] === null
                ? null
                : $this->source->parent->states[$this->config['target']];
        }
    }
}
