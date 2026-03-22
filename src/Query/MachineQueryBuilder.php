<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Query;

use InvalidArgumentException;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Fluent query builder for finding machine instances by state.
 *
 * Wraps the machine_current_states table, providing state-aware
 * filtering with automatic parallel-state deduplication.
 */
class MachineQueryBuilder
{
    /** @var Builder<MachineCurrentState> */
    private readonly Builder $query;

    public function __construct(
        private readonly string $machineClass,
        private readonly MachineDefinition $definition,
    ) {
        $this->query = MachineCurrentState::query()
            ->where('machine_class', $this->machineClass);
    }

    // region Positive Filters

    /**
     * AND filter — instance must have a state matching the given name.
     *
     * Multiple calls = AND (instance must match all). Uses subquery
     * so parallel machines with different matching regions work correctly.
     */
    public function inState(string $state): self
    {
        $resolved = $this->resolveStateIds($state);

        $this->query->whereIn('root_event_id', function (Builder|\Illuminate\Database\Query\Builder $sub) use ($resolved): void {
            $sub->select('root_event_id')
                ->from('machine_current_states')
                ->where('machine_class', $this->machineClass);

            $this->applyStateConditionToQuery($sub, $resolved);
        });

        return $this;
    }

    /**
     * OR filter — instance must be in any of the given states.
     *
     * Conditions are wrapped in a closure to prevent OR from
     * leaking into other query conditions.
     *
     * @param  list<string>  $states
     */
    public function inAnyState(array $states): self
    {
        $allResolved = array_map($this->resolveStateIds(...), $states);

        $this->query->where(function (Builder $q) use ($allResolved): void {
            foreach ($allResolved as $i => $resolved) {
                if ($i === 0) {
                    $this->applyStateConditionToQuery($q, $resolved);
                } else {
                    $q->orWhere(function (Builder $inner) use ($resolved): void {
                        $this->applyStateConditionToQuery($inner, $resolved);
                    });
                }
            }
        });

        return $this;
    }

    // endregion

    // region Negative Filters

    /**
     * Exclude instances that have ANY state matching the given name.
     *
     * Uses NOT IN subquery so parallel instances are fully excluded
     * if any region matches.
     */
    public function notInState(string $state): self
    {
        $resolved = $this->resolveStateIds($state);

        $this->query->whereNotIn('root_event_id', function (Builder|\Illuminate\Database\Query\Builder $sub) use ($resolved): void {
            $sub->select('root_event_id')
                ->from('machine_current_states')
                ->where('machine_class', $this->machineClass);

            $this->applyStateConditionToQuery($sub, $resolved);
        });

        return $this;
    }

    /**
     * Exclude instances in any FINAL state.
     *
     * Collects all FINAL state IDs from the machine definition's idMap
     * and applies NOT IN subquery exclusion.
     */
    public function notInFinalState(): self
    {
        $finalStateIds = $this->collectFinalStateIds();

        if ($finalStateIds === []) {
            return $this;
        }

        $this->query->whereNotIn('root_event_id', function ($sub) use ($finalStateIds): void {
            $sub->select('root_event_id')
                ->from('machine_current_states')
                ->where('machine_class', $this->machineClass)
                ->whereIn('state_id', $finalStateIds);
        });

        return $this;
    }

    /**
     * Alias for notInFinalState().
     */
    public function active(): self
    {
        return $this->notInFinalState();
    }

    /**
     * Only instances where a state_id is a FINAL type.
     */
    public function inFinalState(): self
    {
        $finalStateIds = $this->collectFinalStateIds();

        if ($finalStateIds === []) {
            // No final states defined — nothing can match
            $this->query->whereRaw('1 = 0');

            return $this;
        }

        return $this->inAnyState($finalStateIds);
    }

    // endregion

    // region Time Filters & Ordering

    /**
     * Order results by most recently entered state (applied during hydration).
     */
    public function latest(): self
    {
        return $this;
    }

    /**
     * Order results by oldest entered state (applied during hydration).
     */
    public function oldest(): self
    {
        return $this;
    }

    /**
     * Filter to instances that entered their current state before the given date.
     */
    public function enteredBefore(Carbon $date): self
    {
        $this->query->where('state_entered_at', '<', $date);

        return $this;
    }

    /**
     * Filter to instances that entered their current state after the given date.
     */
    public function enteredAfter(Carbon $date): self
    {
        $this->query->where('state_entered_at', '>', $date);

        return $this;
    }

    // endregion

    // region State Resolution

    /**
     * Resolve a user-provided state name into matchable state IDs.
     *
     * @return array{exact: list<string>, patterns: list<string>}
     */
    public function resolveStateIds(string $stateName): array
    {
        $exact    = [];
        $patterns = [];

        // 1. Wildcard mode
        if (str_contains($stateName, '*')) {
            $qualified = str_contains($stateName, $this->definition->delimiter)
                ? $stateName
                : $this->definition->id.$this->definition->delimiter.$stateName;

            $patterns[] = str_replace('*', '%', $qualified);

            return ['exact' => $exact, 'patterns' => $patterns];
        }

        // 2. Exact match in idMap
        if (isset($this->definition->idMap[$stateName])) {
            $stateDefinition = $this->definition->idMap[$stateName];

            if (in_array($stateDefinition->type, [StateDefinitionType::COMPOUND, StateDefinitionType::PARALLEL], true)) {
                $patterns[] = $stateName.$this->definition->delimiter.'%';
            } else {
                $exact[] = $stateName;
            }

            return ['exact' => $exact, 'patterns' => $patterns];
        }

        // 3. Leaf/suffix match — search for keys ending with ".{$stateName}"
        $suffix = $this->definition->delimiter.$stateName;

        foreach ($this->definition->idMap as $id => $stateDefinition) {
            if (!str_ends_with((string) $id, $suffix)) {
                continue;
            }

            if (in_array($stateDefinition->type, [StateDefinitionType::COMPOUND, StateDefinitionType::PARALLEL], true)) {
                $patterns[] = $id.$this->definition->delimiter.'%';
            } else {
                $exact[] = $id;
            }
        }

        if ($exact !== [] || $patterns !== []) {
            return ['exact' => $exact, 'patterns' => $patterns];
        }

        // 4. No match
        throw new InvalidArgumentException(
            "State '{$stateName}' not found in machine definition '{$this->definition->id}'."
        );
    }

    // endregion

    // region Helpers

    /**
     * Apply resolved state IDs as WHERE conditions to a query or subquery.
     *
     * @param  Builder<MachineCurrentState>|\Illuminate\Database\Query\Builder  $query
     * @param  array{exact: list<string>, patterns: list<string>}  $resolved
     */
    private function applyStateConditionToQuery(Builder|\Illuminate\Database\Query\Builder $query, array $resolved): void
    {
        $hasCondition = false;

        if ($resolved['exact'] !== []) {
            $query->whereIn('state_id', $resolved['exact']);
            $hasCondition = true;
        }

        foreach ($resolved['patterns'] as $pattern) {
            if ($hasCondition) {
                $query->orWhere('state_id', 'LIKE', $pattern);
            } else {
                $query->where('state_id', 'LIKE', $pattern);
                $hasCondition = true;
            }
        }
    }

    /**
     * Collect all FINAL state IDs from the machine definition.
     *
     * @return list<string>
     */
    private function collectFinalStateIds(): array
    {
        $ids = [];

        foreach ($this->definition->idMap as $id => $stateDefinition) {
            if ($stateDefinition->type === StateDefinitionType::FINAL) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    // endregion
}
