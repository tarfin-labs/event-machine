<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

/**
 * Class TransitionDefinition.
 *
 * Represents a transition between states in the state machine.
 */
class TransitionDefinition
{
    // region Public Properties

    /** The target state definition for this transition, or null if there is no target. */
    public ?StateDefinition $target;

    /**
     * The actions to be performed when this transition is taken.
     *
     * @var null|array<string>
     */
    public ?array $actions = null;

    /**
     * The description of the transition.
     */
    public ?string $description = null;

    // endregion

    // region Constructor

    /**
     * Constructs a new TransitionDefinition instance.
     *
     * @param  null|string|array  $transitionConfig The configuration for this transition.
     * @param  StateDefinition  $source The source state definition for this transition.
     * @param  string  $event The event triggering this transition.
     */
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

            $this->actions = $this->transitionConfig['actions'] ?? null;

            $this->description = $this->transitionConfig['description'] ?? null;
        }
    }

    // endregion
}
