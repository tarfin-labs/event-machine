<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\RegionBoundaryMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ReentrantParallelMachine;

/**
 * Enumerate a machine in a bounded subprocess and decode its summary.
 *
 * Out of process because this stub is the reproduction for a crash: before the fix
 * it exited 139 (SIGSEGV), which cannot be caught and which kills a whole parallel
 * worker rather than failing one test. Bounded because the other failure mode is
 * non-termination, and a hung child would stall the suite worse than a killed worker.
 *
 * @return array<string, mixed>
 */
function enumerateOutOfProcess(string $machineClass, ?int $maxDepth = null): array
{
    $script = dirname(__DIR__).'/Support/enumerate-machine.php';

    // PHP_BINARY rather than 'php': it removes the PATH dependency outright and
    // guarantees the subprocess runs the same interpreter as the suite, which matters
    // on a CI matrix that tests several PHP versions.
    $command = [PHP_BINARY, '-d', 'xdebug.mode=off', $script, $machineClass];

    if ($maxDepth !== null) {
        $command[] = (string) $maxDepth;
    }

    $process = new Process($command, dirname(__DIR__, 2));
    $process->setTimeout(30.0);

    try {
        $process->run();
    } catch (Throwable $e) {
        // Non-termination is one of the two failure modes this harness exists to catch,
        // so it has to surface as a legible failure rather than as a stalled suite.
        throw new RuntimeException(
            "Enumeration subprocess did not finish within its bound for {$machineClass}: {$e->getMessage()}",
            previous: $e,
        );
    }

    expect($process->getExitCode())->toBe(
        0,
        'Enumeration subprocess exited with '.var_export($process->getExitCode(), true)
            .' (139 is the SIGSEGV this stub reproduces). stderr: '.$process->getErrorOutput(),
    );

    $summary = json_decode($process->getOutput(), true);

    // A process that never enumerated cannot pass by exiting 0: it has to emit the data.
    expect($summary)->toBeArray()
        ->and($summary['enumerated'] ?? false)->toBeTrue();

    return $summary;
}

test('ReentrantParallelMachine enumerates to completion in a bounded subprocess', function (): void {
    $summary = enumerateOutOfProcess(ReentrantParallelMachine::class);

    // Derived by hand in spec/upcoming-parallel-path-analysis-termination.derivation.md
    // before the enumerator was changed, so these values come from the invariants and
    // the stub definition rather than from the implementation they are guarding.
    expect($summary['path_count'])->toBe(3)
        ->and($summary['types'])->toBe(['happy' => 1, 'loop' => 2])
        ->and($summary['analysis_truncated'])->toBeFalse();
});

test('ReentrantParallelMachine records both parallel states as groups', function (): void {
    $summary = enumerateOutOfProcess(ReentrantParallelMachine::class);

    expect($summary['parallel_group_count'])->toBe(2)
        ->and($summary['parallel_groups'])->toHaveKeys([
            'reentrant_parallel.data_collection',
            'reentrant_parallel.verification',
        ]);
});

test('region enumeration stays inside its own region', function (): void {
    $summary = enumerateOutOfProcess(ReentrantParallelMachine::class);

    $expected = [
        'reentrant_parallel.data_collection' => ['retailer', 'customer_info'],
        'reentrant_parallel.verification'    => ['findeks', 'turmob'],
    ];

    foreach ($expected as $groupId => $regionKeys) {
        foreach ($regionKeys as $regionKey) {
            $region = $summary['parallel_groups'][$groupId]['regions'][$regionKey];

            // One path each, and every step inside the region. Before the boundary
            // existed a region walked the whole machine, so both would be far larger.
            expect($region['path_count'])->toBe(1, "region {$regionKey} path count")
                ->and($region['all_inside'])->toBeTrue("region {$regionKey} left its own subtree");
        }
    }
});

test('a parallel state re-entered from its own transition terminates as a loop', function (): void {
    $summary = enumerateOutOfProcess(ReentrantParallelMachine::class);

    // RESTART is declared on data_collection and targets it; EDIT is declared on
    // verification and targets data_collection. Both are followed at machine level
    // and both re-enter an already-visited parallel state.
    expect($summary['signatures'])->toContain(
        'idle→[START]→data_collection→[RESTART]→data_collection',
        'idle→[START]→data_collection→[@done]→verification→[EDIT]→data_collection',
    );
});

test('a region that records an escape stays inside its own subtree apart from that step', function (): void {
    // The containment check allows exactly one step outside the region: the escape a
    // REGION_EXIT path records, which by definition names a state elsewhere in the machine.
    // That allowance was never exercised — RegionBoundaryMachine is the only machine in the
    // corpus that produces region_exit/region_deferred paths, and nothing asserted containment
    // on it, so deleting the allowance left the whole Analysis suite green. Asserting it here
    // pins both halves: the escape is permitted, and nothing else leaves.
    $summary = enumerateOutOfProcess(RegionBoundaryMachine::class);

    $groups = $summary['parallel_groups'];

    expect($groups)->not->toBeEmpty();

    $sawEscape = false;

    foreach ($groups as $group) {
        foreach ($group['regions'] as $regionKey => $region) {
            expect($region['all_inside'])->toBeTrue("region {$regionKey} left its own subtree");

            foreach ($region['types'] as $type => $count) {
                if ($type === 'region_exit' || $type === 'region_deferred') {
                    $sawEscape = true;
                }
            }
        }
    }

    // Without this the test would pass on any machine whose regions never escape at all.
    expect($sawEscape)->toBeTrue('no region recorded an escape, so the allowance was not exercised');
});

test('the depth ceiling cuts paths short and reports it', function (): void {
    $summary = enumerateOutOfProcess(ReentrantParallelMachine::class, maxDepth: 3);

    expect($summary['depth_limit_reached'])->toBeTrue()
        ->and($summary['analysis_truncated'])->toBeTrue()
        ->and($summary['types'])->toHaveKey('truncated');
});
