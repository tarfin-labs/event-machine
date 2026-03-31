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
     * Find a state by full or partial route using suffix matching.
     *
     * @throws \InvalidArgumentException If route is ambiguous or not found.
     */
    public function resolveState(string $route): StateDefinition
    {
        // 1. Try exact match in idMap
        if (isset($this->definition->idMap[$route])) {
            return $this->definition->idMap[$route];
        }

        // 2. Try with machine prefix
        $prefixed = $this->definition->id.'.'.$route;
        if (isset($this->definition->idMap[$prefixed])) {
            return $this->definition->idMap[$prefixed];
        }

        // 3. Suffix matching — find all states ending with the given route
        $matches = [];
        $suffix  = '.'.$route;

        foreach ($this->definition->idMap as $id => $state) {
            if (str_ends_with((string) $id, $suffix) || $id === $route) {
                $matches[] = $state;
            }
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        if (count($matches) > 1) {
            $ids = array_map(fn (StateDefinition $s): string => $s->id, $matches);
            throw new \InvalidArgumentException(
                "Ambiguous state route '{$route}'. Matches: ".implode(', ', $ids)
            );
        }

        throw new \InvalidArgumentException(
            "State route '{$route}' not found in {$this->definition->id} definition."
        );
    }

    /**
     * Get the underlying machine definition.
     */
    public function definition(): MachineDefinition
    {
        return $this->definition;
    }
}
