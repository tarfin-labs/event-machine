<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Analysis\MachineGraph;
use Tarfinlabs\EventMachine\Analysis\ScenarioPath;
use Tarfinlabs\EventMachine\Analysis\ScenarioPathStep;
use Tarfinlabs\EventMachine\Analysis\ScenarioPathResolver;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioFrontierMachine;

function frontierResolver(int $maxIterations = 1000): ScenarioPathResolver
{
    return new ScenarioPathResolver(new MachineGraph(ScenarioFrontierMachine::definition()), $maxIterations);
}

function keysOf(ScenarioPath $path): array
{
    return array_map(fn (ScenarioPathStep $s): string => $s->stateKey, $path->steps);
}

test('a branch whose target is the resolution target is a one-step path', function (): void {
    $paths = frontierResolver()->resolveAll('entry', 'DIRECT', 'goal');

    // Recorded at seeding time and never placed on the frontier, so it cannot be expanded.
    expect($paths)->toHaveCount(1)
        ->and(keysOf($paths[0]))->toBe(['goal'])
        ->and($paths[0]->totalWeight)->toBe(0);
});

test('a targetless branch contributes no path', function (): void {
    // PARTLY has two branches: one targets hop, the other has actions and no target.
    $paths = frontierResolver()->resolveAll('entry', 'PARTLY', 'goal');

    expect($paths)->toHaveCount(1)
        ->and(keysOf($paths[0]))->toBe(['hop', 'goal']);
});

test('the trigger source is re-entered as a priced step', function (): void {
    // entry --CYCLE--> loop_a --BACK--> entry --DIRECT--> goal.
    // The source is deliberately absent from the seed's visited set, so the cycle is walkable.
    $paths = frontierResolver()->resolveAll('entry', 'CYCLE', 'goal');

    $viaEntry = array_values(array_filter(
        $paths,
        fn (ScenarioPath $p): bool => in_array('entry', keysOf($p), true),
    ));

    expect($viaEntry)->not->toBeEmpty()
        ->and(keysOf($viaEntry[0]))->toBe(['loop_a', 'entry', 'goal'])
        // loop_a (1) + entry (1) + goal (0): the re-entered source is a step and is priced.
        ->and($viaEntry[0]->totalWeight)->toBe(2);
});

test('a cheap route in a later branch is returned before an expensive earlier one', function (): void {
    // fork's @always has costly (PARALLEL, 5) first and thrifty (INTERACTIVE, 1) second.
    $paths = frontierResolver()->resolveAll('entry', 'FORK', 'goal');

    $weights = array_map(fn (ScenarioPath $p): int => $p->totalWeight, $paths);

    expect($paths[0])->not->toBeNull()
        ->and(keysOf($paths[0]))->toContain('thrifty')
        ->and($weights)->toBe(collect($weights)->sort()->values()->all());
});
