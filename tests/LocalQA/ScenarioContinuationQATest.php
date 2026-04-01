<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Routing\MachineRouter;
use Tarfinlabs\EventMachine\Scenarios\ScenarioPlayer;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Scenarios\ScenarioDiscovery;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios\ContinuationScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios\MultiPauseContinuationScenario;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    config()->set('machine.scenarios.enabled', true);
    ScenarioDiscovery::resetCache();
    Machine::resetMachineFakes();

    // Register routes for all tests
    MachineRouter::register(ScenarioTestMachine::class, [
        'prefix'       => '/api/cont-qa',
        'machineIdFor' => ['APPROVE', 'REJECT', 'DELEGATE', 'START_PARALLEL', 'FINISH'],
        'name'         => 'cont-qa',
    ]);
    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
});

/**
 * Helper: create persisted machine at 'reviewing' with ContinuationScenario active in DB.
 *
 * Creates machine via ScenarioPlayer (shouldPersist=false for speed),
 * then manually persists and sets scenario_class in DB.
 */
function createPersistedAtReviewing(string $scenarioClass = ContinuationScenario::class): string
{
    $testMachine = ScenarioTestMachine::startingAt(stateId: 'reviewing');
    $machine     = $testMachine->machine();

    // Force persist
    $machine->definition->shouldPersist = true;
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;

    // Set scenario_class in DB so controller detects continuation
    MachineCurrentState::where('root_event_id', $rootEventId)
        ->update([
            'scenario_class'  => $scenarioClass,
            'scenario_params' => [],
        ]);

    return $rootEventId;
}

// ═══════════════════════════════════════════════════════════════
//  Scenario Continuation — HTTP Endpoint Tests
// ═══════════════════════════════════════════════════════════════

it('LocalQA: full lifecycle — continuation via HTTP reaches final state', function (): void {
    $rootEventId = createPersistedAtReviewing();

    // POST DELEGATE without scenario slug → continuation should auto-activate
    $response = $this->postJson("/api/cont-qa/{$rootEventId}/delegate", [
        'type' => 'DELEGATE',
    ]);

    $response->assertOk();

    // Machine should reach delegation_complete via continuation
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect(str_contains($cs->state_id, 'delegation_complete'))->toBeTrue(
        'Expected delegation_complete, got: '.$cs->state_id
    );

    // Scenario deactivated (final state)
    expect($cs->scenario_class)->toBeNull();
});

it('LocalQA: POST APPROVE without slug — continuation active but APPROVE goes direct to final', function (): void {
    $rootEventId = createPersistedAtReviewing();

    // APPROVE goes to 'approved' (final) — no delegation involved
    // Continuation covers 'delegating', not 'approved'
    // Machine reaches final → scenario deactivated
    $response = $this->postJson("/api/cont-qa/{$rootEventId}/approve", [
        'type' => 'APPROVE',
    ]);

    $response->assertOk();

    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect(str_contains($cs->state_id, 'approved'))->toBeTrue();
    expect($cs->scenario_class)->toBeNull();
});

it('LocalQA: scenario switch — new scenario replaces active continuation', function (): void {
    $rootEventId = createPersistedAtReviewing();

    // Send with a different scenario slug — ContinueLoopScenario
    $response = $this->postJson("/api/cont-qa/{$rootEventId}/approve", [
        'type'     => 'APPROVE',
        'scenario' => 'continue-loop-scenario',
    ]);

    $response->assertOk();

    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect(str_contains($cs->state_id, 'approved'))->toBeTrue();
});

it('LocalQA: scenario_class persisted in DB and cleared after final state', function (): void {
    $rootEventId = createPersistedAtReviewing();

    // Verify DB has scenario_class set before any event
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs->scenario_class)->toBe(ContinuationScenario::class);
    expect($cs->scenario_params)->toBeEmpty();

    // POST APPROVE → final state → scenario deactivated in DB
    $response = $this->postJson("/api/cont-qa/{$rootEventId}/approve", [
        'type' => 'APPROVE',
    ]);
    $response->assertOk();

    // Scenario cleared from DB after final state
    $cs->refresh();
    expect($cs->scenario_class)->toBeNull();
});

it('LocalQA: response activeScenario absent after reaching final state', function (): void {
    $rootEventId = createPersistedAtReviewing();

    // Send DELEGATE → continuation → delegation_complete (final)
    $response = $this->postJson("/api/cont-qa/{$rootEventId}/delegate", [
        'type' => 'DELEGATE',
    ]);

    $response->assertOk();

    $data = $response->json('data');
    // After final state, activeScenario should be null/absent
    expect($data['activeScenario'] ?? null)->toBeNull();
});

it('LocalQA: availableScenarios present in endpoint response', function (): void {
    $rootEventId = createPersistedAtReviewing();

    // POST APPROVE — availableScenarios always present when scenarios enabled
    $response = $this->postJson("/api/cont-qa/{$rootEventId}/approve", [
        'type' => 'APPROVE',
    ]);
    $response->assertOk();

    $data = $response->json('data');
    expect($data)->toHaveKey('availableScenarios');
});

it('LocalQA: no continuation scenario in DB — normal behavior', function (): void {
    // Create machine at reviewing WITHOUT scenario_class in DB
    $testMachine                        = ScenarioTestMachine::startingAt(stateId: 'reviewing');
    $machine                            = $testMachine->machine();
    $machine->definition->shouldPersist = true;
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // POST APPROVE without slug, no scenario in DB → normal behavior
    $response = $this->postJson("/api/cont-qa/{$rootEventId}/approve", [
        'type' => 'APPROVE',
    ]);

    $response->assertOk();

    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect(str_contains($cs->state_id, 'approved'))->toBeTrue();

    $data = $response->json('data');
    expect($data['activeScenario'] ?? null)->toBeNull();
});

it('LocalQA: explicit scenario:null deactivates active continuation', function (): void {
    $rootEventId = createPersistedAtReviewing();

    // Verify continuation is active in DB
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs->scenario_class)->toBe(ContinuationScenario::class);

    // POST with explicit scenario:null — should deactivate, not auto-restore
    $response = $this->postJson("/api/cont-qa/{$rootEventId}/approve", [
        'type'     => 'APPROVE',
        'scenario' => null,
    ]);

    $response->assertOk();

    // Continuation deactivated — scenario_class cleared
    $cs->refresh();
    expect($cs->scenario_class)->toBeNull();

    // Machine handled event normally (no overrides) — reached approved
    expect(str_contains($cs->state_id, 'approved'))->toBeTrue();
});

it('LocalQA: multi-pause continuation — 3 HTTP requests', function (): void {
    // Machine at reviewing with MultiPauseContinuationScenario
    $rootEventId = createPersistedAtReviewing(MultiPauseContinuationScenario::class);

    // Request 2: POST START_PARALLEL without slug → continuation auto-activates
    // Continuation overrides: parallel_check → [IsValidGuard => true]
    // Flow: reviewing → parallel_check → regions auto-complete → @done (guard=true) → all_checked
    $response = $this->postJson("/api/cont-qa/{$rootEventId}/start-parallel", [
        'type' => 'START_PARALLEL',
    ]);

    $response->assertOk();

    // Machine pauses at all_checked (interactive) — scenario still active
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect(str_contains($cs->state_id, 'all_checked'))->toBeTrue(
        'Expected all_checked, got: '.$cs->state_id
    );
    expect($cs->scenario_class)->toBe(MultiPauseContinuationScenario::class);

    // Request 3: POST FINISH → approved (final) → scenario deactivated
    $response = $this->postJson("/api/cont-qa/{$rootEventId}/finish", [
        'type' => 'FINISH',
    ]);

    $response->assertOk();

    $cs->refresh();
    expect(str_contains($cs->state_id, 'approved'))->toBeTrue(
        'Expected approved, got: '.$cs->state_id
    );
    expect($cs->scenario_class)->toBeNull();
});
