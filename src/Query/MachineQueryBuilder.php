<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Query;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\InvalidStateQueryException;

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

    /** Sort direction for hydrated results: 'desc', 'asc', or null. */
    private ?string $sortDirection = null;

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

    /**
     * AND filter — instance must be in ALL of the given states simultaneously.
     *
     * Useful for parallel machines where different regions are in different states.
     * Each state adds a subquery intersection — instance must have matching rows for each.
     *
     * @param  list<string>  $states
     */
    public function inAllStates(array $states): self
    {
        foreach ($states as $state) {
            $this->inState($state);
        }

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

        $this->query->whereNotIn('root_event_id', function (Builder|\Illuminate\Database\Query\Builder $sub) use ($finalStateIds): void {
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
     * Filter instances with an active scenario.
     */
    public function whereHasScenario(): self
    {
        $this->query->whereNotNull('scenario_class');

        return $this;
    }

    /**
     * Filter instances with a specific active scenario.
     */
    public function whereScenario(string $scenarioClass): self
    {
        $this->query->where('scenario_class', $scenarioClass);

        return $this;
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
        $this->sortDirection = 'desc';

        return $this;
    }

    /**
     * Order results by oldest entered state (applied during hydration).
     */
    public function oldest(): self
    {
        $this->sortDirection = 'asc';

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

    // region Terminal Methods

    /**
     * Execute the query and return deduplicated, hydrated results.
     *
     * @return Collection<int, MachineQueryResult>
     */
    public function get(): Collection
    {
        $rootEventIds = $this->buildIdsQuery()->pluck('root_event_id');

        return $this->hydrate($rootEventIds);
    }

    /**
     * Get the first result, or null if no matches.
     *
     * Optimized: limits to 1 ID before hydration when no ordering is applied.
     */
    public function first(): ?MachineQueryResult
    {
        if ($this->sortDirection !== null) {
            return $this->get()->first();
        }

        $rootEventId = $this->buildIdsQuery()->limit(1)->pluck('root_event_id');

        return $this->hydrate($rootEventId)->first();
    }

    /**
     * Count distinct machine instances matching the query.
     */
    public function count(): int
    {
        return (int) $this->query->count(DB::raw('DISTINCT root_event_id'));
    }

    /**
     * Get just the root_event_ids of matching instances.
     *
     * @return Collection<int, string>
     */
    public function pluckMachineIds(): Collection
    {
        return $this->buildIdsQuery()->pluck('root_event_id');
    }

    /**
     * Paginate deduplicated results.
     *
     * Loads all matching IDs, hydrates and sorts in PHP, then slices
     * for the requested page. Suitable for hundreds to low-thousands
     * of active instances per machine class.
     */
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        $total   = $this->count();
        $allIds  = $this->buildIdsQuery()->pluck('root_event_id');
        $results = $this->hydrate($allIds);

        $page   = LengthAwarePaginator::resolveCurrentPage();
        $sliced = $results->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator($sliced, $total, $perPage, $page);
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
        throw InvalidStateQueryException::stateNotFound($stateName, $this->definition->id);
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
     * Build a query that returns distinct root_event_ids matching the filters.
     *
     * @return Builder<MachineCurrentState>
     */
    private function buildIdsQuery(): Builder
    {
        return (clone $this->query)
            ->select('root_event_id')
            ->distinct();
    }

    /**
     * Fetch all rows for matched instance IDs, group by instance, build results.
     *
     * @param  Collection<int, string>  $rootEventIds
     *
     * @return Collection<int, MachineQueryResult>
     */
    private function hydrate(Collection $rootEventIds): Collection
    {
        if ($rootEventIds->isEmpty()) {
            return collect();
        }

        $allRows = MachineCurrentState::query()
            ->whereIn('root_event_id', $rootEventIds)
            ->where('machine_class', $this->machineClass)
            ->get();

        $results = $allRows->groupBy('root_event_id')
            ->map(function (Collection $rows, string $rootEventId): MachineQueryResult {
                $stateIds       = $rows->pluck('state_id')->all();
                $representative = $rows->sortByDesc('state_entered_at')->first();

                return new MachineQueryResult(
                    machineId: $rootEventId,
                    stateId: $representative->state_id,
                    stateEnteredAt: $representative->state_entered_at,
                    stateIds: $stateIds,
                    machineClass: $this->machineClass,
                );
            });

        if ($this->sortDirection !== null) {
            $results = $this->sortDirection === 'desc'
                ? $results->sortByDesc('stateEnteredAt')
                : $results->sortBy('stateEnteredAt');
        }

        return $results->values();
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
