<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Enums\BehaviorType;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Exceptions\NoStateDefinitionFoundException;

/**
 * Class TransitionBranch.
 *
 * This class represents a transition branch in an event machine.
 * It defines the target state, actions, guards, and description for the branch.
 */
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

    /** The description of the transition branch. */
    public ?string $description = null;

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
            $targetStateDefinition = $this
                ->transitionDefinition
                ->source
                ->machine
                ->getNearestStateDefinitionByString($this->transitionBranchConfig);

            // If the target state definition is not found, throw an exception
            if ($targetStateDefinition === null) {
                throw NoStateDefinitionFoundException::build(
                    from: $this->transitionDefinition->source->id,
                    to: $this->transitionBranchConfig,
                    eventType: $this->transitionDefinition->event,
                );
            }

            $this->target = $targetStateDefinition;

            return;
        }

        if (is_array($this->transitionBranchConfig)) {
            if (empty($this->target)) {
                $this->target = null;
            }

            if (isset($this->transitionBranchConfig['target'])) {
                $targetStateDefinition = $this->transitionDefinition
                    ->source
                    ->machine
                    ->getNearestStateDefinitionByString($this->transitionBranchConfig['target']);

                if ($targetStateDefinition === null) {
                    throw NoStateDefinitionFoundException::build(
                        from: $this->transitionDefinition->source->id,
                        to: $this->transitionBranchConfig['target'],
                        eventType: $this->transitionDefinition->event,
                    );
                }

                $this->target = $targetStateDefinition;
            }

            $this->description = $this->transitionBranchConfig['description'] ?? null;

            $this->initializeGuards();
            $this->initializeActions();
        }
    }

    /**
     * Initializes the guard/s for this transition.
     */
    protected function initializeGuards(): void
    {
        if (isset($this->transitionBranchConfig[BehaviorType::Guard->value])) {
            $this->guards = is_array($this->transitionBranchConfig[BehaviorType::Guard->value])
                ? $this->transitionBranchConfig[BehaviorType::Guard->value]
                : [$this->transitionBranchConfig[BehaviorType::Guard->value]];

            $this->initializeInlineBehaviors(
                inlineBehaviors: $this->guards,
                behaviorType: BehaviorType::Guard
            );
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

            $this->initializeInlineBehaviors(
                inlineBehaviors: $this->actions,
                behaviorType: BehaviorType::Action
            );
        }
    }

    /**
     * Adds inline behavior definitions to machine's behavior.
     *
     * @param  array  $inlineBehaviors An array of inline behaviors.
     * @param  BehaviorType  $behaviorType The type of behavior.
     */
    protected function initializeInlineBehaviors(array $inlineBehaviors, BehaviorType $behaviorType): void
    {
        foreach ($inlineBehaviors as $behavior) {
            // If the behavior contains a colon, it means that it has a parameter.
            if (str_contains($behavior, ':')) {
                $behavior = explode(':', $behavior)[0];
            }

            // If the behavior is class of a known behavior type (e.g. Guard, Action, etc.), add it to the machine's behavior too.
            if (is_subclass_of($behavior, class: $behaviorType->getBehaviorClass())) {
                $this
                    ->transitionDefinition
                    ->source
                    ->machine
                    ->behavior[$behaviorType->value][$behavior::getType()] = $behavior;
            }
        }
    }

    /**
     * Execute the actions associated with transition definition.
     *
     * If there are no actions associated with the transition definition, do nothing.
     *
     * @param  EventBehavior|null  $eventBehavior The event or null if none is provided.
     */
    public function runActions(
        State $state,
        EventBehavior $eventBehavior = null
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
