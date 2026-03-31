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
        $total = count($this->enumeration->paths);

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
