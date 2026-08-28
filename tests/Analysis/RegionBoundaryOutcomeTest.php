<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Analysis\PathType;
use Tarfinlabs\EventMachine\Analysis\MachinePath;
use Tarfinlabs\EventMachine\Analysis\PathEnumerator;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\RegionBoundaryMachine;

/**
 * @return array<string, list<MachinePath>>
 */
function regionPathsOf(MachineDefinition $definition): array
{
    $result = (new PathEnumerator($definition))->enumerate();

    $regions = [];

    foreach ($result->parallelGroups as $group) {
        foreach ($group->regionPaths as $key => $paths) {
            $regions[$key] = $paths;
        }
    }

    return $regions;
}

test('a region transition that leaves the region is recorded as a region exit', function (): void {
    $regions = regionPathsOf(RegionBoundaryMachine::definition());

    // ESCALATE is declared inside the retailer region and targets a state outside it.
    // At runtime this re-points that region's slot while the parallel state stays
    // active, so it is neither a dead end nor something machine level represents.
    expect($regions['retailer'])->toHaveCount(1)
        ->and($regions['retailer'][0]->type)->toBe(PathType::REGION_EXIT)
        ->and($regions['retailer'][0]->signature())->toBe('awaiting_vehicle→[ESCALATE]→escalated');
});

test('a region state whose only continuation is ancestor-declared is not a dead end', function (): void {
    $regions = regionPathsOf(RegionBoundaryMachine::definition());

    // awaiting_customer has no transitions of its own and inherits only ABORT from the
    // parallel state. The runtime can leave it, so DEAD_END would be a false claim; the
    // transition belongs to machine level, so a region exit would be false too.
    expect($regions['customer_info'])->toHaveCount(1)
        ->and($regions['customer_info'][0]->type)->toBe(PathType::REGION_DEFERRED)
        ->and($regions['customer_info'][0]->type)->not->toBe(PathType::DEAD_END);
});

test('an @always that leaves its region records an outcome rather than silence', function (): void {
    $regions = regionPathsOf(RegionBoundaryMachine::definition());

    // This reaches handleAlwaysPriority's unguarded-fallback path, which returned
    // without recording anything. Region @always edges are runtime-reachable, so
    // silence there would drop a real edge from the analysis entirely.
    expect($regions['documents'])->toHaveCount(1)
        ->and($regions['documents'][0]->type)->toBe(PathType::REGION_EXIT)
        ->and($regions['documents'][0]->signature())->toBe('routing→[@always]→escalated');
});

// A companion test for an explicit config['id'] inside a region was attempted and
// removed: such a state cannot be reached at all. StateDefinition::buildId() honours
// the override, but findInitialStateDefinition() builds its lookup from the parent id
// and delimiter, and target resolution finds the state by neither its key nor its
// overridden id — so no enumerable region path can contain one. The structural
// membership test therefore rests on the delimiter case below, which is reachable.

test('region membership survives a custom delimiter', function (): void {
    // The delimiter is a per-machine setting, so a boundary test that assumes a dot
    // would misjudge membership on this machine.
    $definition = MachineDefinition::define(config: [
        'id'        => 'delimiter_probe',
        'delimiter' => '::',
        'initial'   => 'idle',
        'states'    => [
            'idle'    => ['on' => ['START' => 'working']],
            'working' => [
                'type'   => 'parallel',
                'on'     => ['ABORT' => 'aborted'],
                'states' => [
                    'alpha' => [
                        'initial' => 'first',
                        'states'  => [
                            'first'  => ['on' => ['NEXT' => 'second']],
                            'second' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'aborted' => ['type' => 'final'],
        ],
    ]);

    $regions = regionPathsOf($definition);

    expect($regions['alpha'])->toHaveCount(1)
        ->and($regions['alpha'][0]->type)->toBe(PathType::HAPPY);
});
