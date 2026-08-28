<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Analysis\MachinePath;
use Tarfinlabs\EventMachine\Analysis\PathEnumerator;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * 'mid' is reached first by a short route, which caches its suffix, and again by a
 * longer one. The cache is keyed on state id alone, so the second visit replays what
 * the first discovered — which is where a prefix-dependent decision can be laundered.
 */
function replayDefinition(): MachineDefinition
{
    return MachineDefinition::define(config: [
        'id'      => 'replay_probe',
        'initial' => 'idle',
        'states'  => [
            'idle'  => ['on' => ['SHORT' => 'mid', 'LONG' => 'a']],
            'a'     => ['on' => ['N' => 'b']],
            'b'     => ['on' => ['N' => 'c']],
            'c'     => ['on' => ['N' => 'mid']],
            'mid'   => ['on' => ['N' => 'tail1']],
            'tail1' => ['on' => ['N' => 'tail2']],
            'tail2' => ['type' => 'final'],
        ],
    ]);
}

test('a cached suffix replays normally when it fits under the ceiling', function (): void {
    $result = (new PathEnumerator(replayDefinition()))->enumerate();

    $lengths = array_map(static fn (MachinePath $p): int => count($p->steps), $result->paths);

    sort($lengths);

    expect($result->paths)->toHaveCount(2)
        ->and($lengths)->toBe([4, 7])
        ->and($result->analysisTruncated())->toBeFalse();
});

test('a cached suffix is cut at the ceiling rather than replayed over it', function (): void {
    // Without the depth check on replay this emits the full 7-step path over a ceiling
    // of 6, with no flag reporting it — an over-length path nothing accounts for.
    $result = (new PathEnumerator(replayDefinition(), 1000, null, 6))->enumerate();

    $byType = [];

    foreach ($result->paths as $path) {
        $byType[$path->type->value][] = count($path->steps);
    }

    expect($result->depthLimitReached)->toBeTrue()
        ->and($byType)->toHaveKey('truncated')
        ->and(max($byType['truncated']))->toBe(6)
        ->and(max(array_merge(...array_values($byType))))->toBeLessThanOrEqual(6);
});

test('no enumerated path ever exceeds the ceiling', function (): void {
    // The invariant behind the previous test, stated directly: whatever route the DFS
    // takes and whatever the cache replays, nothing longer than the ceiling is emitted.
    foreach ([3, 4, 5, 6, 7] as $maxDepth) {
        $result = (new PathEnumerator(replayDefinition(), 1000, null, $maxDepth))->enumerate();

        foreach ($result->paths as $path) {
            expect(count($path->steps))->toBeLessThanOrEqual($maxDepth, "maxDepth={$maxDepth}");
        }
    }
});

test('a suffix set containing a truncated path is not cached', function (): void {
    // Truncation is a property of the prefix that reached a state, not of the state, so
    // caching it would invent truncation for a shallower prefix that reaches the same
    // state later. Here the LONG route truncates at 'mid' and the SHORT route must not
    // inherit that: it stays a complete path.
    $definition = MachineDefinition::define(config: [
        'id'      => 'cache_order_probe',
        'initial' => 'idle',
        'states'  => [
            // The long route is declared first, so DFS explores and caches it first.
            'idle'  => ['on' => ['LONG' => 'a', 'SHORT' => 'mid']],
            'a'     => ['on' => ['N' => 'b']],
            'b'     => ['on' => ['N' => 'c']],
            'c'     => ['on' => ['N' => 'mid']],
            'mid'   => ['on' => ['N' => 'tail1']],
            'tail1' => ['on' => ['N' => 'tail2']],
            'tail2' => ['type' => 'final'],
        ],
    ]);

    $result = (new PathEnumerator($definition, 1000, null, 6))->enumerate();

    $short = array_values(array_filter(
        $result->paths,
        static fn (MachinePath $p): bool => str_contains($p->signature(), '[SHORT]'),
    ));

    expect($short)->toHaveCount(1)
        ->and($short[0]->type->value)->toBe('happy')
        ->and($short[0]->signature())->toBe('idle→[SHORT]→mid→[N]→tail1→[N]→tail2');
});

test('a parallel state reached twice still records its regions each time', function (): void {
    // The cache hit returns before the type dispatch, so a cached parallel state would
    // skip handleParallel entirely — no region enumeration under its boundary and no
    // depth accounting on the second encounter.
    $definition = MachineDefinition::define(config: [
        'id'      => 'twice_reached_parallel',
        'initial' => 'idle',
        'states'  => [
            'idle'    => ['on' => ['VIA_A' => 'route_a', 'VIA_B' => 'route_b']],
            'route_a' => ['on' => ['GO' => 'shared']],
            'route_b' => ['on' => ['GO' => 'shared']],
            'shared'  => [
                'type'   => 'parallel',
                '@done'  => 'finished',
                'states' => [
                    'x' => ['initial' => 'x1', 'states' => ['x1' => ['on' => ['XD' => 'x2']], 'x2' => ['type' => 'final']]],
                    'y' => ['initial' => 'y1', 'states' => ['y1' => ['on' => ['YD' => 'y2']], 'y2' => ['type' => 'final']]],
                ],
            ],
            'finished' => ['type' => 'final'],
        ],
    ]);

    $result = (new PathEnumerator($definition))->enumerate();

    // Both routes reach the parallel state and both complete through it.
    expect($result->paths)->toHaveCount(2)
        ->and($result->parallelGroups)->toHaveCount(1);

    $group = $result->parallelGroups[0];

    expect($group->regionPaths)->toHaveKeys(['x', 'y'])
        ->and($group->regionPaths['x'])->toHaveCount(1)
        ->and($group->regionPaths['y'])->toHaveCount(1);
});

test('a region sub-enumerator inherits the remaining path budget', function (): void {
    // The budget is shared rather than re-issued in full per region: once the caller has
    // spent it, the region has nothing left and says so instead of enumerating freely.
    $definition = MachineDefinition::define(config: [
        'id'      => 'budget_probe',
        'initial' => 'working',
        'states'  => [
            'working' => [
                'type'   => 'parallel',
                '@done'  => 'finished',
                'states' => [
                    'x' => ['initial' => 'x1', 'states' => ['x1' => ['on' => ['XD' => 'x2']], 'x2' => ['type' => 'final']]],
                ],
            ],
            'finished' => ['type' => 'final'],
        ],
    ]);

    $generous = (new PathEnumerator($definition, 1000))->enumerate();
    $starved  = (new PathEnumerator($definition, 0))->enumerate();

    expect($generous->parallelGroups[0]->regionPaths['x'])->not->toBeEmpty()
        ->and($generous->pathLimitReached)->toBeFalse()
        ->and($starved->parallelGroups[0]->regionPaths['x'])->toBeEmpty()
        ->and($starved->pathLimitReached)->toBeTrue()
        ->and($starved->analysisTruncated())->toBeTrue();
});
