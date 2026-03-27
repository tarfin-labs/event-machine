<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\ScenariosDisabledException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\MachineWithScenarios;
use Tarfinlabs\EventMachine\Tests\Stubs\Scenarios\TrafficLightsActiveScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

/*
 * Tests verifying old (deprecated) and new scenario systems coexist without interference.
 * Remove these tests when the old scenario system is removed in v9.
 */

it('old scenario system works independently when new system is disabled', function (): void {
    config(['machine.scenarios.enabled' => false]);

    $machine = MachineWithScenarios::create();

    // Old system: scenarioType in payload triggers state swap
    $state = $machine->send(['type' => 'EVENT_B', 'payload' => ['scenarioType' => 'test']]);

    expect($state->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'test.state_c']);
    expect($state->context->count)->toBe(-2);
});

it('new scenario system works independently when old system is not configured', function (): void {
    config(['machine.scenarios.enabled' => true]);

    // TrafficLightsMachine does NOT have scenarios_enabled — old system is off for this machine
    $result = TrafficLightsActiveScenario::play();

    expect($result->currentState)->toBe('active');
    expect($result->stepsExecuted)->toBe(3);
});

it('old and new systems do not interfere on the same machine', function (): void {
    config(['machine.scenarios.enabled' => true]);

    // Step 1: Use OLD system on MachineWithScenarios (has scenarios_enabled: true)
    $oldMachine = MachineWithScenarios::create();
    $oldState   = $oldMachine->send(['type' => 'EVENT_B', 'payload' => ['scenarioType' => 'test']]);

    // Old system should work: scenario state swap happened
    expect($oldState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'test.state_c']);

    // Step 2: Use NEW system on TrafficLightsMachine (separate machine, no old scenarios)
    $newResult = TrafficLightsActiveScenario::play();

    // New system should work: scenario replayed events
    expect($newResult->currentState)->toBe('active');
    expect($newResult->stepsExecuted)->toBe(3);

    // Step 3: Verify old machine was not affected by new system activation
    $oldState2 = $oldMachine->send('EVENT_D');
    expect($oldState2->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'test.state_a']);
});

it('scenarioType payload (old) and scenario request field (new) use different mechanisms', function (): void {
    config(['machine.scenarios.enabled' => true]);

    // Old: scenarioType goes into event payload → used by getScenarioStateIfAvailable
    // New: scenario goes into HTTP request body → used by MachineController::maybePlayScenario
    // They are different fields parsed at different layers — no conflict

    $machine = MachineWithScenarios::create();

    // Send event with scenarioType (old system) — this is the event payload
    $state = $machine->send([
        'type'    => 'EVENT_B',
        'payload' => ['scenarioType' => 'test'],
    ]);

    // Old system activated: state swapped to scenario path
    expect($state->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'test.state_c']);

    // The 'scenario' key (new system) would only be parsed by MachineController at the HTTP layer,
    // not by Machine::send() — so even if both keys were present, they target different layers
});

it('config namespaces do not collide — scenarios_enabled vs machine.scenarios.enabled', function (): void {
    // Old system: uses 'scenarios_enabled' inside machine config array (root level)
    // New system: uses 'machine.scenarios.enabled' in Laravel config (app-level)
    // They are completely independent

    // New system disabled, old system enabled (via machine config)
    config(['machine.scenarios.enabled' => false]);

    $machine = MachineWithScenarios::create(); // has scenarios_enabled: true in its config
    $state   = $machine->send(['type' => 'EVENT_B', 'payload' => ['scenarioType' => 'test']]);

    // Old system works even when new system is disabled
    expect($state->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'test.state_c']);

    // New system would throw ScenariosDisabledException if called
    expect(fn () => TrafficLightsActiveScenario::play())
        ->toThrow(ScenariosDisabledException::class);
});
