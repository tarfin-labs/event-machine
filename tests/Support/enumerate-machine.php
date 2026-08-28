<?php

declare(strict_types=1);

/**
 * Enumerate a machine in an isolated process and print a JSON summary.
 *
 * The reproduction for the parallel path-analysis crash has to run out of process.
 * Its two failure modes are both invisible to an in-process assertion: SIGSEGV cannot
 * be caught and, under parallel Pest, takes a whole worker down with it rather than
 * failing one test; and non-termination produces no result to assert on at all.
 *
 * The summary is emitted as data so the caller asserts on what was actually enumerated.
 * A process that never enumerated cannot pass by reporting success.
 *
 * Usage: php enumerate-machine.php <MachineFQCN> [maxDepth]
 */

use Tarfinlabs\EventMachine\Analysis\MachinePath;
use Tarfinlabs\EventMachine\Analysis\PathEnumerator;
use Tarfinlabs\EventMachine\Definition\StateDefinition;

require __DIR__.'/../../vendor/autoload.php';

$machineClass = $argv[1] ?? null;
$maxDepth     = isset($argv[2]) ? (int) $argv[2] : 200;

if ($machineClass === null || !class_exists($machineClass)) {
    fwrite(STDERR, "usage: enumerate-machine.php <MachineFQCN> [maxDepth]\n");

    exit(2);
}

$definition = $machineClass::definition();
$result     = (new PathEnumerator($definition, 1000, null, $maxDepth))->enumerate();

/** Count paths by type value. */
$countByType = static function (array $paths): array {
    $types = [];

    foreach ($paths as $path) {
        $types[$path->type->value] = ($types[$path->type->value] ?? 0) + 1;
    }

    ksort($types);

    return $types;
};

/**
 * Is every step of this path inside the given region?
 *
 * Structural, by walking StateDefinition::$parent — the same rule the enumerator's
 * boundary uses, and for the same reason: an id-prefix test is unsound because
 * buildId() honours an explicit config['id'] and the delimiter is per-machine.
 * A REGION_EXIT path is allowed one final step naming the target outside the region.
 */
$stepsInsideRegion = static function (MachinePath $path, StateDefinition $region, array $idMap): bool {
    $steps = $path->steps;

    if ($path->type->value === 'region_exit' && $steps !== []) {
        array_pop($steps);
    }

    foreach ($steps as $step) {
        $state = $idMap[$step->stateId] ?? null;

        if (!$state instanceof StateDefinition) {
            return false;
        }

        $inside  = false;
        $current = $state;

        while ($current instanceof StateDefinition) {
            if ($current === $region) {
                $inside = true;

                break;
            }

            $current = $current->parent;
        }

        if (!$inside) {
            return false;
        }
    }

    return true;
};

$groups = [];

foreach ($result->parallelGroups as $group) {
    $parallelState = $definition->idMap[$group->parallelStateId] ?? null;
    $regions       = [];

    foreach ($group->regionPaths as $regionKey => $paths) {
        $region = $parallelState?->stateDefinitions[$regionKey] ?? null;

        $allInside = true;

        if ($region instanceof StateDefinition) {
            foreach ($paths as $path) {
                if (!$stepsInsideRegion($path, $region, $definition->idMap)) {
                    $allInside = false;

                    break;
                }
            }
        }

        $regions[$regionKey] = [
            'path_count' => count($paths),
            'types'      => $countByType($paths),
            'all_inside' => $allInside,
            'signatures' => array_map(static fn (MachinePath $p): string => $p->signature(), $paths),
        ];
    }

    $groups[$group->parallelStateId] = [
        'combinations' => $group->combinationCount(),
        'regions'      => $regions,
    ];
}

echo json_encode([
    'enumerated'           => true,
    'machine'              => $definition->id,
    'path_count'           => count($result->paths),
    'types'                => $countByType($result->paths),
    'signatures'           => array_map(static fn (MachinePath $p): string => $p->signature(), $result->paths),
    'parallel_group_count' => count($result->parallelGroups),
    'parallel_groups'      => $groups,
    'path_limit_reached'   => $result->pathLimitReached,
    'depth_limit_reached'  => $result->depthLimitReached,
    'analysis_truncated'   => $result->analysisTruncated(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
