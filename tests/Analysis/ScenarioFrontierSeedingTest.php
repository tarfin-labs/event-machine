<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Analysis\ScenarioPath;

test('a branch whose target is the resolution target is a one-step path', function (): void {
    $paths = scenarioFrontierResolver()->resolveAll('entry', 'DIRECT', 'goal');

    // Recorded at seeding time and never placed on the frontier, so it cannot be expanded.
    expect($paths)->toHaveCount(1)
        ->and(scenarioStateKeys($paths[0]))->toBe(['goal'])
        ->and($paths[0]->totalWeight)->toBe(0);
});

test('a targetless branch contributes no path', function (): void {
    // PARTLY has two branches: one targets hop, the other has actions and no target.
    $paths = scenarioFrontierResolver()->resolveAll('entry', 'PARTLY', 'goal');

    expect($paths)->toHaveCount(1)
        ->and(scenarioStateKeys($paths[0]))->toBe(['hop', 'goal']);
});

test('the trigger source is re-entered as a priced step', function (): void {
    // entry --CYCLE--> loop_a --BACK--> entry --DIRECT--> goal.
    // The source is deliberately absent from the seed's visited set, so the cycle is walkable.
    $paths = scenarioFrontierResolver()->resolveAll('entry', 'CYCLE', 'goal');

    $viaEntry = array_values(array_filter(
        $paths,
        fn (ScenarioPath $p): bool => in_array('entry', scenarioStateKeys($p), true),
    ));

    expect($viaEntry)->not->toBeEmpty()
        ->and(scenarioStateKeys($viaEntry[0]))->toBe(['loop_a', 'entry', 'goal'])
        // loop_a (1) + entry (1) + goal (0): the re-entered source is a step and is priced.
        ->and($viaEntry[0]->totalWeight)->toBe(2);
});

test('a cheap route below a later branch is returned before an expensive earlier one', function (): void {
    // fork's @always has costly (PARALLEL, 5) first and thrifty (INTERACTIVE, 1) second. FORK
    // itself is a single-branch trigger, so what orders these two is the global sort.
    $paths = scenarioFrontierResolver()->resolveAll('entry', 'FORK', 'goal');

    $weights = array_map(fn (ScenarioPath $p): int => $p->totalWeight, $paths);

    expect($paths[0])->not->toBeNull()
        ->and(scenarioStateKeys($paths[0]))->toContain('thrifty')
        ->and($weights)->toBe(collect($weights)->sort()->values()->all());
});

test('a cheap route reachable only through the trigger second branch is returned first', function (): void {
    // SPLIT's own branches: costly (PARALLEL 5, @done to goal) first, thrifty (INTERACTIVE 1,
    // CONFIRM to goal) second. Both become seeds on ONE frontier, so this is the shape the
    // per-branch search got wrong — it would have exhausted the first branch before giving the
    // second an expansion, and returned the weight-5 route ahead of the weight-1 one.
    $paths = scenarioFrontierResolver()->resolveAll('split_entry', 'SPLIT', 'goal');

    expect($paths)->toHaveCount(2)
        ->and(scenarioStateKeys($paths[0]))->toBe(['thrifty', 'goal'])
        ->and($paths[0]->totalWeight)->toBe(1)
        ->and(scenarioStateKeys($paths[1]))->toBe(['costly', 'goal'])
        ->and($paths[1]->totalWeight)->toBe(5);
});
