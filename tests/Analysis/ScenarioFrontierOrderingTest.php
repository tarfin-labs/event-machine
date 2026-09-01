<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Analysis\MachineGraph;
use Tarfinlabs\EventMachine\Analysis\ScenarioPath;
use Tarfinlabs\EventMachine\Analysis\ScenarioPathStep;
use Tarfinlabs\EventMachine\Analysis\ScenarioPathResolver;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioFrontierMachine;

function cappedResolver(int $maxIterations): ScenarioPathResolver
{
    return new ScenarioPathResolver(new MachineGraph(ScenarioFrontierMachine::definition()), $maxIterations);
}

function stateKeys(ScenarioPath $path): array
{
    return array_map(fn (ScenarioPathStep $s): string => $s->stateKey, $path->steps);
}

test('an exhausted resolution does not report truncation', function (): void {
    $resolver = cappedResolver(1000);
    $paths    = $resolver->resolveAll('entry', 'TIE', 'tie_goal');

    expect($paths)->toHaveCount(2)
        ->and($resolver->wasTruncated())->toBeFalse();
});

test('a cap that leaves a seed queued reports truncation', function (): void {
    $resolver = cappedResolver(1);
    $resolver->resolveAll('entry', 'TIE', 'tie_goal');

    // One expansion runs; the second seed is still on the frontier when the loop stops.
    expect($resolver->wasTruncated())->toBeTrue();
});

test('among equal-cost seeds the first-inserted one is expanded first', function (): void {
    // tie_a and tie_b are both INTERACTIVE, so both seeds carry cost 1. With a single
    // expansion available, only the branch seeded first can reach the target. This is the
    // one row a bare -$cost priority queue fails: with no tie-break, which seed comes out
    // is left to heap internals.
    $paths = cappedResolver(1)->resolveAll('entry', 'TIE', 'tie_goal');

    expect($paths)->toHaveCount(1)
        ->and(stateKeys($paths[0]))->toBe(['tie_a', 'tie_goal']);
});

test('a free route can spend the budget before an expensive seed is expanded', function (): void {
    // Seeds: free_1 (TRANSIENT, cost 0) and pricey (PARALLEL, cost 5). deep_goal lives inside
    // pricey's region, so it is reachable only through the expensive seed.
    $uncapped = cappedResolver(1000);
    $found    = $uncapped->resolveAll('entry', 'BUDGET', 'deep_goal');

    expect($found)->not->toBeEmpty()
        ->and($uncapped->wasTruncated())->toBeFalse();

    // Three expansions all go to the zero-cost chain, because cost order prefers it.
    $capped  = cappedResolver(3);
    $missing = $capped->resolveAll('entry', 'BUDGET', 'deep_goal');

    expect($missing)->toBeEmpty()
        ->and($capped->wasTruncated())->toBeTrue();
});
