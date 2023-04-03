<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

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

    /** The guards to be checked before this transition is taken. */
    public ?array $guards = null;

    /** The description of the transition. */
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
            $targetConfig = $this->transitionConfig['target'] ?? null;
            $this->target = $targetConfig === null
                ? null
                : $this->source->parent->states[$targetConfig];

            $this->initializeConditions();
            $this->initializeActions();

            $this->description = $this->transitionConfig['description'] ?? null;
        }
    }

    // endregion

    // region Protected Methods

    protected function initializeConditions(): void
    {
        if (isset($this->transitionConfig['guards'])) {
            $this->guards = is_array($this->transitionConfig['guards'])
                ? $this->transitionConfig['guards']
                : [$this->transitionConfig['guards']];
        } else {
            $this->guards = null;
        }
    }

    /**
     * Initializes the action/s for this transition.
     */
    protected function initializeActions(): void
    {
        if (isset($this->transitionConfig['actions'])) {
            $this->actions = is_array($this->transitionConfig['actions'])
                ? $this->transitionConfig['actions']
                : [$this->transitionConfig['actions']];
        } else {
            $this->actions = null;
        }
    }

    // endregion

    // region Public Methods

    /**
     * Execute the actions associated with transition definition.
     *
     * If there are no actions associated with the transition definition, do nothing.
     *
     * @param  \Tarfinlabs\EventMachine\Behavior\EventBehavior|null  $eventBehavior  The event data or null if none is provided.
     */
    public function runActions(?EventBehavior $eventBehavior = null): void
    {
        if ($this->actions === null) {
            return;
        }

        foreach ($this->actions as $actionDefinition) {
            $this->source->machine->runAction($actionDefinition, $eventBehavior);
        }
    }

    // endregion
}
