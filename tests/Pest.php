<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Analysis\MachineGraph;
use Tarfinlabs\EventMachine\Analysis\ScenarioPath;
use Tarfinlabs\EventMachine\Analysis\ScenarioPathStep;
use Tarfinlabs\EventMachine\Analysis\ScenarioPathResolver;
use Tarfinlabs\EventMachine\Testing\InteractsWithMachines;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\QueryBuilderTestMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioFrontierMachine;

uses(
    TestCase::class,
    RefreshDatabase::class,
    InteractsWithMachines::class,
)->in(
    'Actor', 'Analysis', 'Architecture', 'Behavior', 'Commands', 'Definition',
    'E2E', 'Examples', 'Features', 'Integration', 'Jobs',
    'Models', 'Query', 'Routing', 'Services', 'Support',
);

/*
|--------------------------------------------------------------------------
| Fake Cleanup
|--------------------------------------------------------------------------
|
| Reset all Fakeable trait mocks between tests. resetAllFakes() clears
| ALL faked behaviors across ALL classes (the $fakes array is shared via
| InvokableBehavior). Call it from any behavior class in afterEach().
|
| Example (add to test files that use fakes):
|   afterEach(fn() => IncrementAction::resetAllFakes());
|
*/

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * The state keys a scenario path visits, in order.
 *
 * Lives here because five test files needed exactly this mapper and each grew its own copy
 * under a different name — Pest shares one global function namespace, so the duplicates were
 * renamed rather than reconciled.
 *
 * @return list<string>
 */
function scenarioStateKeys(ScenarioPath $path): array
{
    return array_map(static fn (ScenarioPathStep $s): string => $s->stateKey, $path->steps);
}

/**
 * A resolver over ScenarioFrontierMachine, optionally capped.
 */
function scenarioFrontierResolver(int $maxIterations = 1000): ScenarioPathResolver
{
    return new ScenarioPathResolver(new MachineGraph(ScenarioFrontierMachine::definition()), $maxIterations);
}

/**
 * Create and persist a QueryBuilderTestMachine in the given state.
 */
function createPersistedQBMachine(string $targetState = 'idle'): Machine
{
    $machine = QueryBuilderTestMachine::create();
    $machine->persist();

    if ($targetState === 'active') {
        $machine->send(['type' => 'START']);
    } elseif ($targetState === 'completed') {
        $machine->send(['type' => 'START']);
        $machine->send(['type' => 'FINISH']);
    }

    return $machine;
}
