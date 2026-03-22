<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Query;

use InvalidArgumentException;
use Illuminate\Database\Eloquent\Builder;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Fluent query builder for finding machine instances by state.
 *
 * Wraps the machine_current_states table, providing state-aware
 * filtering with automatic parallel-state deduplication.
 */
class MachineQueryBuilder
{
    public function __construct(private readonly MachineDefinition $definition) {}

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
}
