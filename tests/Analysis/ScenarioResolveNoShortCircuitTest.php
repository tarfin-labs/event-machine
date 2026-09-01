<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Analysis\MachineGraph;
use Tarfinlabs\EventMachine\Analysis\ScenarioPath;
use Tarfinlabs\EventMachine\Analysis\ScenarioPathStep;
use Tarfinlabs\EventMachine\Analysis\ScenarioPathResolver;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioShortCircuitMachine;

function shortCircuitPaths(): array
{
    $resolver = new ScenarioPathResolver(new MachineGraph(ScenarioShortCircuitMachine::definition()));

    return $resolver->resolveAll('start', 'GO', 't');
}

function routeOf(ScenarioPath $path): array
{
    return array_map(fn (ScenarioPathStep $s): string => $s->stateKey, $path->steps);
}

test('resolve returns resolveAll first element', function (): void {
    $resolver = new ScenarioPathResolver(new MachineGraph(ScenarioShortCircuitMachine::definition()));

    expect(routeOf($resolver->resolve('start', 'GO', 't')))
        ->toBe(routeOf($resolver->resolveAll('start', 'GO', 't')[0]));
});

test('resolve returns the shorter of two equally priced routes', function (): void {
    $paths = shortCircuitPaths();

    // Both weigh 3; the long route is five steps and the short one four.
    expect($paths)->toHaveCount(2)
        ->and(array_map(fn (ScenarioPath $p): int => $p->totalWeight, $paths))->toBe([3, 3])
        ->and(routeOf($paths[0]))->toBe(['a', 'm', 'n', 't'])
        ->and(routeOf($paths[1]))->toBe(['a', 'b', 'c', 'd', 't']);
});

test('the route resolve rejects is the one recorded first', function (): void {
    // This is what makes the previous test discriminating rather than incidental. The long
    // route accumulates its weight late, so its last pre-target step enters the cost band
    // ahead of the short route's and is recorded first. An implementation that returned the
    // first target it reached would return the five-step route; the step-count key does not.
    // A cap set between the two recordings exposes the order without reading internals: with
    // five expansions the long route is already recorded and the short one is not.
    $capped = new ScenarioPathResolver(new MachineGraph(ScenarioShortCircuitMachine::definition()), 5);
    $early  = $capped->resolveAll('start', 'GO', 't');

    expect(array_map(routeOf(...), $early))->toBe([['a', 'b', 'c', 'd', 't']])
        ->and($capped->wasTruncated())->toBeTrue();

    // Uncapped, that same longer route is the one resolve() declines to return.
    $resolver = new ScenarioPathResolver(new MachineGraph(ScenarioShortCircuitMachine::definition()));

    expect(routeOf($resolver->resolve('start', 'GO', 't')))->toBe(['a', 'm', 'n', 't']);
});
