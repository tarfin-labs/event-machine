<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Analysis;

/**
 * Compares enumerated paths against observed test paths.
 *
 * Matching is by signature string equality.
 */
class PathCoverageReport
{
    /** @var list<array{path: MachinePath, tests: list<string>}> */
    private array $covered;

    /** @var list<MachinePath> */
    private array $uncovered;

    /**
     * @param  PathEnumerationResult  $enumeration  All enumerated paths from static analysis.
     * @param  list<array{signature: string, test: string}>  $observedPaths  Paths recorded by PathCoverageTracker.
     */
    public function __construct(
        private readonly PathEnumerationResult $enumeration,
        private readonly array $observedPaths,
    ) {
        $this->computeCoverage();
    }

    /**
     * @return list<array{path: MachinePath, tests: list<string>}>
     */
    public function coveredPaths(): array
    {
        return $this->covered;
    }

    /**
     * @return list<MachinePath>
     */
    public function uncoveredPaths(): array
    {
        return $this->uncovered;
    }

    public function coveragePercentage(): float
    {
        // The denominator is the paths that were actually counted, which excludes the
        // ones computeCoverage() skips as unmatchable. Reading it off $enumeration->paths
        // would leave a permanently uncoverable path in the denominator and put 100% out
        // of reach.
        $total = count($this->covered) + count($this->uncovered);

        if ($total === 0) {
            return 100.0;
        }

        return round(count($this->covered) / $total * 100, 1);
    }

    /**
     * Get the test names that covered a specific path.
     *
     * @return list<string>
     */
    public function testedBy(MachinePath $path): array
    {
        $signature = $path->stateSignature();

        foreach ($this->covered as $entry) {
            if ($entry['path']->stateSignature() === $signature) {
                return $entry['tests'];
            }
        }

        return [];
    }

    private function computeCoverage(): void
    {
        $this->covered   = [];
        $this->uncovered = [];

        // Index observed signatures → test names
        $observedIndex = [];

        foreach ($this->observedPaths as $observed) {
            $observedIndex[$observed['signature']][] = $observed['test'];
        }

        foreach ($this->enumeration->paths as $path) {
            // A truncated path is an incomplete prefix: enumeration stopped part way
            // through it, so no observed run can ever match its signature. Counting it
            // would make assertAllPathsCovered() unsatisfiable on any machine whose
            // analysis hit a ceiling. Every pre-existing path kind keeps its current
            // treatment — GUARD_BLOCK in particular stays counted, because changing that
            // would move figures for suites that pass today.
            if ($path->type === PathType::TRUNCATED) {
                continue;
            }

            $signature = $path->stateSignature();

            if (isset($observedIndex[$signature])) {
                $this->covered[] = [
                    'path'  => $path,
                    'tests' => array_values(array_unique($observedIndex[$signature])),
                ];
            } else {
                $this->uncovered[] = $path;
            }
        }
    }
}
