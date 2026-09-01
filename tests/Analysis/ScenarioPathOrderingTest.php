<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Analysis\MachineGraph;
use Tarfinlabs\EventMachine\Analysis\ScenarioPath;
use Tarfinlabs\EventMachine\Analysis\ScenarioPathStep;
use Tarfinlabs\EventMachine\Analysis\ScenarioPathResolver;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioOrderingMachine;

function orderingPaths(): array
{
    $resolver = new ScenarioPathResolver(new MachineGraph(ScenarioOrderingMachine::definition()));

    return $resolver->resolveAll('pending', 'SUBMIT', 'approved');
}

function signatureOf(ScenarioPath $path): string
{
    return implode('→', array_map(fn (ScenarioPathStep $s): string => $s->stateKey, $path->steps));
}

test('cheaper paths come before more expensive ones', function (): void {
    $paths = orderingPaths();

    $weights = array_map(fn (ScenarioPath $p): int => $p->totalWeight, $paths);

    expect($weights)->toBe([3, 3, 3, 4, 7])
        ->and($weights)->toBe(collect($weights)->sort()->values()->all());
});

test('resolve returns the cheapest route, not the parallel one', function (): void {
    $resolver = new ScenarioPathResolver(new MachineGraph(ScenarioOrderingMachine::definition()));

    $cheapest = $resolver->resolve('pending', 'SUBMIT', 'approved');

    // The spec's worked example: the parallel route weighs 7 over the same four steps.
    expect($cheapest->totalWeight)->toBe(3)
        ->and($cheapest->steps)->toHaveCount(4)
        ->and(signatureOf($cheapest))->not->toContain('verification');
});

test('among equally priced paths the shorter one comes first', function (): void {
    $paths = orderingPaths();

    // The three weight-3 routes: two of four steps, one of five.
    $stepCounts = array_map(
        fn (ScenarioPath $p): int => count($p->steps),
        array_filter($paths, fn (ScenarioPath $p): bool => $p->totalWeight === 3),
    );

    expect(array_values($stepCounts))->toBe([4, 4, 5]);
});

test('paths tied on weight and length are both returned, in no promised order', function (): void {
    $paths = orderingPaths();

    $tied = array_map(
        signatureOf(...),
        array_filter(
            $paths,
            fn (ScenarioPath $p): bool => $p->totalWeight === 3 && count($p->steps) === 4,
        ),
    );

    // Membership only. §2.7 leaves the order of paths tied on both keys unspecified,
    // so asserting which of these comes first would assert something the spec refuses
    // to promise — and would fail the next time the search changes.
    expect($tied)->toHaveCount(2)
        ->and($tied)->toContain('eligibility→manual_check→review→approved')
        ->and($tied)->toContain('eligibility→alt_check→review→approved');
});

test('when every step is interactive the order is by step count', function (): void {
    $paths = orderingPaths();

    // manual (3 interactive steps + final) against long (4 + final): with no zero-weight
    // step between them, cost is the step count, so ordering by cost orders by length.
    $interactiveOnly = array_values(array_filter(
        $paths,
        fn (ScenarioPath $p): bool => !str_contains(signatureOf($p), 'slow_hop')
            && !str_contains(signatureOf($p), 'verification'),
    ));

    $pairs = array_map(
        fn (ScenarioPath $p): array => [$p->totalWeight, count($p->steps)],
        $interactiveOnly,
    );

    expect($pairs)->toBe([[3, 4], [3, 4], [4, 5]]);
});
