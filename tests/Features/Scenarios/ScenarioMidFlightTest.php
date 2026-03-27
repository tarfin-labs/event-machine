<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Scenarios\ScenarioResult;
use Tarfinlabs\EventMachine\Exceptions\ScenarioFailedException;
use Tarfinlabs\EventMachine\Tests\Stubs\Scenarios\MidFlightFinishScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Scenarios\MidFlightMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Scenarios\MidFlightToActiveScenario;

beforeEach(function (): void {
    config(['machine.scenarios.enabled' => true]);
});

it('plays a mid-flight scenario on an existing machine', function (): void {
    // First, bring machine to 'active' state
    $setupResult = MidFlightToActiveScenario::play();
    expect($setupResult->currentState)->toBe('active');

    // Now play mid-flight scenario to finish it
    $result = MidFlightFinishScenario::playOn($setupResult->rootEventId);

    expect($result)
        ->toBeInstanceOf(ScenarioResult::class)
        ->currentState->toBe('done')
        ->stepsExecuted->toBe(1);
});

it('validates from() state and throws on mismatch', function (): void {
    // Create a machine and send an event so it has a root_event_id,
    // then use ACTIVATE to get it to 'active', but we need it at 'idle'.
    // Instead, send ACTIVATE and then try to play a scenario that expects 'idle'.
    $setupResult = MidFlightToActiveScenario::play();

    // Machine is now at 'active', but create a scenario that expects 'idle'
    // We'll use MidFlightFinishScenario which expects 'active' — but first
    // let's finish the machine to 'done' and then try MidFlightFinishScenario
    $machine = MidFlightMachine::create(state: $setupResult->rootEventId);
    $machine->send(['type' => 'FINISH']);
    $rootEventId = $machine->state->history->first()->root_event_id;

    // MidFlightFinishScenario expects 'active', but machine is at 'done'
    MidFlightFinishScenario::playOn($rootEventId);
})->throws(ScenarioFailedException::class, "Expected machine to be in state 'active', but found 'done'");

it('skips model creation in mid-flight mode', function (): void {
    $setupResult = MidFlightToActiveScenario::play();

    $result = MidFlightFinishScenario::playOn($setupResult->rootEventId);

    // Mid-flight returns empty models (existing models are not tracked)
    expect($result->models)->toBe([]);
});

it('accepts parameter overrides in mid-flight mode', function (): void {
    $setupResult = MidFlightToActiveScenario::play();

    // playOn accepts params — even if this scenario doesn't use them,
    // the mechanism should work without error
    $result = MidFlightFinishScenario::playOn($setupResult->rootEventId, ['extra_param' => 'value']);

    expect($result->currentState)->toBe('done');
});

it('restores the correct machine state for mid-flight', function (): void {
    $setupResult = MidFlightToActiveScenario::play();

    $result = MidFlightFinishScenario::playOn($setupResult->rootEventId);

    // Verify machine was restored and continued from active → done
    $machine = MidFlightMachine::create(state: $result->rootEventId);
    expect($machine->state->currentStateDefinition->key)->toBe('done');
});
