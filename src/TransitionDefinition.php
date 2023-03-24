<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

class TransitionDefinition
{
    public ?StateDefinition $target;

    public function __construct(
        public null|string|array $transitionConfig,
        public StateDefinition $source,
        public string $event,
    ) {
        if ($this->transitionConfig === null) {
            $this->target = null;
        }

        if (is_string($this->transitionConfig)) {
            $this->target = $this->source->parent->states[$this->transitionConfig];
        }

        if (is_array($this->transitionConfig)) {
            $this->target = $this->transitionConfig['target'] === null
                ? null
                : $this->source->parent->states[$this->transitionConfig['target']];
        }
    }
}
