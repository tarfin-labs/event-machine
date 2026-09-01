<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Analysis\MachineGraph;
use Tarfinlabs\EventMachine\Analysis\ScenarioPath;
use Tarfinlabs\EventMachine\Analysis\ScenarioPathStep;
use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Analysis\StateClassification;
use Tarfinlabs\EventMachine\Analysis\ScenarioPathResolver;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;

function weightResolver(): ScenarioPathResolver
{
    return new ScenarioPathResolver(new MachineGraph(ScenarioTestMachine::definition()));
}

test('totalWeight sums the weight of every step', function (): void {
    $path = new ScenarioPath([
        new ScenarioPathStep(stateRoute: 'routing', stateKey: 'routing', classification: StateClassification::TRANSIENT),
        new ScenarioPathStep(stateRoute: 'processing', stateKey: 'processing', classification: StateClassification::DELEGATION),
        new ScenarioPathStep(stateRoute: 'reviewing', stateKey: 'reviewing', classification: StateClassification::INTERACTIVE),
        new ScenarioPathStep(stateRoute: 'approved', stateKey: 'approved', classification: StateClassification::FINAL),
    ]);

    // 0 + 3 + 1 + 0
    expect($path->totalWeight)->toBe(4);
});

test('an empty path weighs nothing', function (): void {
    expect((new ScenarioPath([]))->totalWeight)->toBe(0);
});

test('the source state is never priced', function (): void {
    $resolver = weightResolver();

    // reviewing is INTERACTIVE (weight 1) and is the SOURCE, so it is not a step.
    // The single step is approved (FINAL, weight 0) — 1 if the source were counted.
    $toFinal = $resolver->resolve('reviewing', 'APPROVE', 'approved');

    // Same source, but a DELEGATION target (weight 3) — 4 if the source were counted.
    $toDelegation = $resolver->resolve('reviewing', 'DELEGATE', 'delegating');

    expect($toFinal->steps)->toHaveCount(1)
        ->and($toFinal->totalWeight)->toBe(0)
        ->and($toDelegation->steps)->toHaveCount(1)
        ->and($toDelegation->totalWeight)->toBe(3);
});

test('a route of only free classifications weighs nothing', function (): void {
    // idle --@always--> routing (TRANSIENT) --@always--> blocked (FINAL)
    $path = weightResolver()->resolve('idle', MachineScenario::START, 'blocked');

    $classifications = array_map(
        fn (ScenarioPathStep $step): StateClassification => $step->classification,
        $path->steps,
    );

    expect($classifications)->each->toBeIn([
        StateClassification::TRANSIENT,
        StateClassification::COMPOUND,
        StateClassification::FINAL,
    ])->and($path->totalWeight)->toBe(0);
});
