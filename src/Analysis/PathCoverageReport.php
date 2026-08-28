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
     * Was the enumeration this report was computed over cut short?
     *
     * A coverage figure over an incomplete enumeration is not a coverage
     * guarantee: the paths that were never enumerated cannot be counted as
     * uncovered, so the percentage flatters itself. Callers that gate on the
     * percentage read this alongside it.
     */
    public function enumerationTruncated(): bool
    {
        return $this->enumeration->analysisTruncated();
    }

    /**
     * Was the enumeration cut short by the DEPTH ceiling specifically?
     *
     * Separated from enumerationTruncated() because the two ceilings differ in what a
     * caller can do about them. The depth ceiling arrived with this analysis work and
     * nothing depends on it yet; the path ceiling has been firing silently at its
     * default for far longer, and the in-suite assertions expose no way to raise it,
     * so failing on it would hand consumers a break they cannot fix.
     */
    public function depthTruncated(): bool
    {
        return $this->enumeration->depthLimitReached;
    }

    /**
     * How many enumerated paths were skipped as unmatchable.
     */
    public function skippedPathCount(): int
    {
        return count($this->enumeration->paths) - count($this->covered) - count($this->uncovered);
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
