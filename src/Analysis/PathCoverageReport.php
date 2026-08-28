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

    /** @var list<string> Observed signatures with no enumerated path to match them. */
    private array $unmatchedObserved;

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

    /**
     * Signatures a run actually walked that no enumerated path matches.
     *
     * Each one is a route the machine took and the analysis does not know about, so a
     * non-empty list means the enumeration is incomplete regardless of what the
     * percentage says. Reported rather than failed: a coverage file left over from an
     * older definition produces the same trace, and only the caller can tell them apart.
     *
     * @return list<string>
     */
    public function unmatchedObservations(): array
    {
        return $this->unmatchedObserved;
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
     * Separated from enumerationTruncated() so a report can name which ceiling it hit.
     * Both now fail the in-suite assertions and both are parameters on them, so this is
     * a reporting distinction rather than a policy one: it tells a caller whether to
     * raise maxDepth or maxPaths, not whether the result may be trusted.
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
        $this->covered           = [];
        $this->uncovered         = [];
        $this->unmatchedObserved = [];

        // Index observed signatures → test names
        $observedIndex = [];

        foreach ($this->observedPaths as $observed) {
            $observedIndex[$observed['signature']][] = $observed['test'];
        }

        $matched = [];

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
                $matched[$signature] = true;

                $this->covered[] = [
                    'path'  => $path,
                    'tests' => array_values(array_unique($observedIndex[$signature])),
                ];
            } else {
                $this->uncovered[] = $path;
            }
        }

        // A signature a run actually walked, with no enumerated path to match it, is
        // free evidence that the enumeration missed something the machine can do.
        // Discarding it let the report answer 100% while holding the proof it was
        // incomplete. It is disclosed rather than failed: a stale coverage file left over
        // from an older definition leaves the same trace, and that is the caller's to
        // judge.
        foreach (array_keys($observedIndex) as $signature) {
            if (!isset($matched[$signature])) {
                $this->unmatchedObserved[] = $signature;
            }
        }
    }
}
