<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Analysis;

use Tarfinlabs\EventMachine\Enums\TransitionProperty;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Definition\StateDefinition;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Definition\TransitionDefinition;

/**
 * Shared graph utility for navigating machine definitions.
 * Used by PathEnumerator (coverage) and ScenarioPathResolver (scaffolding).
 */
class MachineGraph
{
    public function __construct(
        private readonly MachineDefinition $definition,
    ) {}

    /**
     * All transitions available from a state, including inherited parent chain.
     * Equivalent to the former PathEnumerator::collectAllTransitions().
     *
     * @return array<string, TransitionDefinition>
     */
    public function transitionsFrom(StateDefinition $state): array
    {
        $transitions = [];

        // Start with the state's own transitions
        if ($state->transitionDefinitions !== null) {
            foreach ($state->transitionDefinitions as $event => $transition) {
                $transitions[$event] = $transition;
            }
        }

        // Walk up parent chain INCLUDING root — add transitions for events NOT already seen.
        $current = $state->parent;

        while ($current instanceof StateDefinition) {
            if ($current->transitionDefinitions !== null) {
                foreach ($current->transitionDefinitions as $event => $transition) {
                    if (!isset($transitions[$event])) {
                        $transitions[$event] = $transition;
                    }
                }
            }

            $current = $current->parent;
        }

        return $transitions;
    }

    /**
     * Classify a state by its structural role.
     */
    public function classifyState(StateDefinition $state): StateClassification
    {
        // Final states
        if ($state->type === StateDefinitionType::FINAL) {
            return StateClassification::FINAL;
        }

        // Parallel states
        if ($state->type === StateDefinitionType::PARALLEL) {
            return StateClassification::PARALLEL;
        }

        // Delegation states (machine or job invoke)
        if ($state->hasMachineInvoke()) {
            return StateClassification::DELEGATION;
        }

        // Transient states (have @always transition)
        $transitions = $this->transitionsFrom($state);
        if (isset($transitions[TransitionProperty::Always->value])) {
            return StateClassification::TRANSIENT;
        }

        // Everything else is interactive
        return StateClassification::INTERACTIVE;
    }

    /**
     * Get the underlying machine definition.
     */
    public function definition(): MachineDefinition
    {
        return $this->definition;
    }
}
