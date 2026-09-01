<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Analysis\MachineGraph;
use Tarfinlabs\EventMachine\Analysis\ScenarioPath;
use Tarfinlabs\EventMachine\Analysis\ScenarioPathStep;
use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Analysis\StateClassification;
use Tarfinlabs\EventMachine\Analysis\ScenarioPathResolver;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioCompoundTestMachine;

function descentResolver(string $machine): ScenarioPathResolver
{
    return new ScenarioPathResolver(new MachineGraph($machine::definition()));
}

/**
 * Each step as [state key, classification value, weight] — the three things a pricing
 * assertion needs side by side.
 *
 * @return list<array{0: string, 1: string, 2: int}>
 */
function pathShape(ScenarioPath $path): array
{
    return array_map(
        fn (ScenarioPathStep $s): array => [$s->stateKey, $s->classification->value, $s->classification->weight()],
        $path->steps,
    );
}

test('descending into a compound child is not collapsed into one step', function (): void {
    $path = descentResolver(ScenarioCompoundTestMachine::class)
        ->resolve('idle', MachineScenario::START, 'checking_phone_cache');

    // The compound and the child it descends into are separate steps, each priced on its own
    // classification — collapsing them would give one step and hide the child entirely.
    expect(pathShape($path))->toBe([
        ['phone_resolution', 'compound', 0],
        ['checking_phone_cache', 'transient', 0],
    ]);
});

test('each descended step is priced by its own classification', function (): void {
    $path = descentResolver(ScenarioCompoundTestMachine::class)
        ->resolve('idle', MachineScenario::START, 'matching_phone');

    // Three steps: the compound at 0, the transient at 0, and the interactive leaf at 1.
    expect(pathShape($path))->toBe([
        ['phone_resolution', 'compound', 0],
        ['checking_phone_cache', 'transient', 0],
        ['matching_phone', 'interactive', 1],
    ])->and($path->totalWeight)->toBe(1);
});

test('a target inside one region is reached only through that region', function (): void {
    $paths = descentResolver(ScenarioTestMachine::class)
        ->resolveAll('reviewing', 'START_PARALLEL', 'b_done');

    expect($paths)->not->toBeEmpty();

    foreach ($paths as $path) {
        $keys = array_column(pathShape($path), 0);

        // Region A is simultaneously live at runtime but contributes no step and no weight to
        // a path that projects through region B.
        expect($keys)->toContain('checking_b')
            ->and($keys)->not->toContain('checking_a')
            ->and($keys)->not->toContain('a_done');
    }
});

test('leaving a parallel state by its own done is priced once, on the parallel state', function (): void {
    $path = descentResolver(ScenarioTestMachine::class)
        ->resolve('reviewing', 'START_PARALLEL', 'all_checked');

    // The parallel state is one step at 5 and its @done target is a step of its own, priced on
    // its own classification. No region contributes a step, even though @done fires only when
    // every region is final — which is exactly the under-price the derivation file records.
    expect(pathShape($path))->toBe([
        ['parallel_check', StateClassification::PARALLEL->value, 5],
        ['all_checked', StateClassification::INTERACTIVE->value, 1],
    ])->and($path->totalWeight)->toBe(6);
});
