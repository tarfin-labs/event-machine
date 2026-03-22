<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Analysis;

/**
 * Result of path enumeration on a machine definition.
 *
 * Contains all enumerated paths, parallel region groups,
 * and provides filtering by path type.
 */
readonly class PathEnumerationResult
{
    /**
     * @param  list<MachinePath>  $paths  All enumerated terminal paths.
     * @param  list<ParallelPathGroup>  $parallelGroups  Per-region path groups for parallel states.
     */
    public function __construct(
        public array $paths = [],
        public array $parallelGroups = [],
    ) {}

    /**
     * @return list<MachinePath>
     */
    public function happyPaths(): array
    {
        return $this->filterByType(PathType::HAPPY);
    }

    /**
     * @return list<MachinePath>
     */
    public function failPaths(): array
    {
        return $this->filterByType(PathType::FAIL);
    }

    /**
     * @return list<MachinePath>
     */
    public function timeoutPaths(): array
    {
        return $this->filterByType(PathType::TIMEOUT);
    }

    /**
     * @return list<MachinePath>
     */
    public function loopPaths(): array
    {
        return $this->filterByType(PathType::LOOP);
    }

    /**
     * @return list<MachinePath>
     */
    public function guardBlockPaths(): array
    {
        return $this->filterByType(PathType::GUARD_BLOCK);
    }

    /**
     * @return list<MachinePath>
     */
    public function deadEndPaths(): array
    {
        return $this->filterByType(PathType::DEAD_END);
    }

    /**
     * @return list<MachinePath>
     */
    private function filterByType(PathType $type): array
    {
        return array_values(array_filter(
            $this->paths,
            static fn (MachinePath $path): bool => $path->type === $type,
        ));
    }
}
