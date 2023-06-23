<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Behavior\BehaviorType;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class TransitionBranch
{
    // region Public Properties

    /** The target state definition for this transition branch, or null if there is no target. */
    public ?StateDefinition $target;

    /**
     * The actions to be performed when this transition branch is taken.
     *
     * @var null|array<string>
     */
    public ?array $actions = null;

    /** The guards to be checked before this transition branch is taken. */
    public ?array $guards = null;

    // endregion

    // region Constructor

    /**
     * Constructs a new TransitionBranch instance.
     */
    public function __construct(
        public null|string|array $transitionBranchConfig,
        public TransitionDefinition $transitionDefinition,
    ) {
        if ($this->transitionBranchConfig === null) {
            $this->target = null;
        }

        if (is_string($this->transitionBranchConfig)) {
            $this->target = $this
                ->transitionDefinition
                ->source
                ->machine
                ->getNearestStateDefinitionByString($this->transitionBranchConfig);

            return;
        }

        if (is_array($this->transitionBranchConfig)) {
            $this->target = (!isset($this->transitionBranchConfig['target']) || $this->transitionBranchConfig['target'] === null
                    ? null
                    : $this->transitionDefinition->source->parent->stateDefinitions[$this->transitionBranchConfig['target']]);

            $this->initializeConditions();
            $this->initializeActions();
        }
    }

    /**
     * Initializes the guard/s for this transition.
     */
    protected function initializeConditions(): void
    {
        if (isset($this->transitionBranchConfig[BehaviorType::Guard->value])) {
            $this->guards = is_array($this->transitionBranchConfig[BehaviorType::Guard->value])
                ? $this->transitionBranchConfig[BehaviorType::Guard->value]
                : [$this->transitionBranchConfig[BehaviorType::Guard->value]];
        }
    }

    /**
     * Initializes the action/s for this transition.
     */
    protected function initializeActions(): void
    {
        if (isset($this->transitionBranchConfig[BehaviorType::Action->value])) {
            $this->actions = is_array($this->transitionBranchConfig[BehaviorType::Action->value])
                ? $this->transitionBranchConfig[BehaviorType::Action->value]
                : [$this->transitionBranchConfig[BehaviorType::Action->value]];
        }
    }

    /**
     * Execute the actions associated with transition definition.
     *
     * If there are no actions associated with the transition definition, do nothing.
     *
     * @param  \Tarfinlabs\EventMachine\Behavior\EventBehavior|null  $eventBehavior  The event or null if none is provided.
     */
    public function runActions(
        State $state,
        ?EventBehavior $eventBehavior = null
    ): void {
        if ($this->actions === null) {
            return;
        }

        foreach ($this->actions as $actionDefinition) {
            $this->transitionDefinition->source->machine->runAction($actionDefinition, $state, $eventBehavior);
        }
    }

    // endregion
}
