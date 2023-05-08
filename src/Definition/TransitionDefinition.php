<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\BehaviorType;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;

/**
 * Class TransitionDefinition.
 *
 * Represents a transition between states in the state machine.
 */
class TransitionDefinition
{
    // region Public Properties

    public TransitionType $type;

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
        $this->type = match ($this->event) {
            TransitionType::Always->value => TransitionType::Always,
            default                       => TransitionType::Normal,
        };

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
        if (isset($this->transitionConfig[BehaviorType::Guard->value])) {
            $this->guards = is_array($this->transitionConfig[BehaviorType::Guard->value])
                ? $this->transitionConfig[BehaviorType::Guard->value]
                : [$this->transitionConfig[BehaviorType::Guard->value]];
        } else {
            $this->guards = null;
        }
    }

    /**
     * Initializes the action/s for this transition.
     */
    protected function initializeActions(): void
    {
        if (isset($this->transitionConfig[BehaviorType::Action->value])) {
            $this->actions = is_array($this->transitionConfig[BehaviorType::Action->value])
                ? $this->transitionConfig[BehaviorType::Action->value]
                : [$this->transitionConfig[BehaviorType::Action->value]];
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
     * @param  \Tarfinlabs\EventMachine\Behavior\EventBehavior|null  $eventBehavior  The event or null if none is provided.
     */
    public function runActions(ContextManager $context, ?EventBehavior $eventBehavior = null): void
    {
        if ($this->actions === null) {
            return;
        }

        foreach ($this->actions as $actionDefinition) {
            $this->source->machine->runAction($actionDefinition, $context, $eventBehavior);
        }
    }

    // endregion
}
