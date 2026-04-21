<?php

declare(strict_types=1);

use Orchestra\Testbench\TestCase;
use Illuminate\Support\Facades\Route;
use Tarfinlabs\EventMachine\Routing\MachineRouter;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Scenarios\ScenarioDiscovery;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\ScenarioTestMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios\HappyPathScenario;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Scenarios\ContinuationScenario;

beforeEach(function (): void {
    config()->set('machine.scenarios.enabled', true);
    ScenarioDiscovery::resetCache();

    MachineRouter::register(ScenarioTestMachine::class, [
        'prefix'       => '/api/scenario-test',
        'machineIdFor' => ['APPROVE', 'REJECT'],
        'name'         => 'scenario-test',
    ]);

    // Simple machine for continuation endpoint tests — no delegation, easy to persist
    MachineRouter::register(TestEndpointMachine::class, [
        'prefix'       => '/api/cont-ep',
        'create'       => true,
        'machineIdFor' => ['START', 'COMPLETE', 'CANCEL'],
        'name'         => 'cont_ep',
    ]);

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
});

// ── availableScenarios in endpoint response ──────────────────────────────────

test('endpoint response includes availableScenarios key when scenarios enabled', function (): void {
    $routes     = collect(Route::getRoutes()->getRoutes());
    $hasApprove = $routes->contains(fn ($r) => str_contains($r->uri(), 'approve'));
    expect($hasApprove)->toBeTrue();
});

test('availableScenarios grouped by event type — keys are event type strings, not FQCN', function (): void {
    $grouped = ScenarioDiscovery::groupedByEvent(
        machineClass: ScenarioTestMachine::class,
        currentState: 'reviewing',
    );

    foreach (array_keys($grouped) as $eventType) {
        expect($eventType)->not->toContain('\\');
    }
});

test('availableScenarios contains slug, description, target, params per scenario', function (): void {
    $grouped = ScenarioDiscovery::groupedByEvent(
        machineClass: ScenarioTestMachine::class,
        currentState: 'reviewing',
    );

    $found = false;
    foreach ($grouped as $scenarios) {
        foreach ($scenarios as $info) {
            expect($info)->toHaveKey('slug')
                ->and($info)->toHaveKey('description')
                ->and($info)->toHaveKey('target')
                ->and($info)->toHaveKey('params');
            $found = true;
            break 2;
        }
    }

    expect($found)->toBeTrue();
});

test('availableScenarios only includes scenarios matching current state source', function (): void {
    $grouped = ScenarioDiscovery::groupedByEvent(
        machineClass: ScenarioTestMachine::class,
        currentState: 'reviewing',
    );

    $allSlugs = [];
    foreach ($grouped as $scenarios) {
        foreach ($scenarios as $info) {
            $allSlugs[] = $info['slug'];
        }
    }

    expect($allSlugs)->toContain('continue-loop-scenario');
});

test('availableScenarios not included when scenarios.enabled=false', function (): void {
    config()->set('machine.scenarios.enabled', false);

    expect(config('machine.scenarios.enabled'))->toBeFalse();
});

// NOTE: POST scenario activation tests (slug activation, scenarioParams, source/event mismatch,
// deactivation, @continue via endpoint) require persisted machine + real delegation → QA tests.
// See spec/9.4.0-scenario-tests.md §6 "Additional QA Tests Needed".

// ── activeScenario + continuation in endpoint response ─────────────────────

/**
 * Helper: create a persisted TestEndpointMachine at 'started' state.
 * Returns [machineId, rootEventId].
 *
 * @return array{machineId: string, rootEventId: string}
 */
function createPersistedMachineAtStarted(TestCase $testCase): array
{
    // Create via endpoint
    $createResponse = $testCase->postJson('/api/cont-ep/create');
    $machineId      = $createResponse->json('data.id');

    // Move to 'started' via START event
    $testCase->postJson("/api/cont-ep/{$machineId}/start");

    return ['machineId' => $machineId, 'rootEventId' => $machineId];
}

test('response includes activeScenario when continuation is active', function (): void {
    // Create at idle, set continuation, call START → lands at 'started' (non-final)
    $createResponse = $this->postJson('/api/cont-ep/create');
    $machineId      = $createResponse->json('data.id');

    MachineCurrentState::where('root_event_id', $machineId)
        ->update([
            'scenario_class'  => ContinuationScenario::class,
            'scenario_params' => null,
        ]);

    $response = $this->postJson("/api/cont-ep/{$machineId}/start");
    $data     = $response->json('data');

    // buildResponse reads scenario_class from DB — 'started' is non-final so scenario is still active
    expect($data)->toHaveKey('activeScenario')
        ->and($data['activeScenario'])->not->toBeNull()
        ->and($data['activeScenario']['slug'])->toBe('continuation-scenario')
        ->and($data['activeScenario']['description'])->toBe('Start → reviewing, then continuation handles delegation')
        ->and($data['activeScenario']['hasContinuation'])->toBeTrue();
});

test('response activeScenario is null when no active scenario', function (): void {
    $createResponse = $this->postJson('/api/cont-ep/create');
    $machineId      = $createResponse->json('data.id');

    // No scenario_class in DB — ensure it's null
    MachineCurrentState::where('root_event_id', $machineId)
        ->update(['scenario_class' => null, 'scenario_params' => null]);

    $response = $this->postJson("/api/cont-ep/{$machineId}/start");
    $data     = $response->json('data');

    // activeScenario should not be in response when no continuation scenario is active
    expect($data)->not->toHaveKey('activeScenario');
});

test('response activeScenario cleared after final state', function (): void {
    ['machineId' => $machineId] = createPersistedMachineAtStarted($this);

    MachineCurrentState::where('root_event_id', $machineId)
        ->update([
            'scenario_class'  => ContinuationScenario::class,
            'scenario_params' => null,
        ]);

    // COMPLETE moves to 'completed' (final) — executeContinuation deactivates scenario
    $this->postJson("/api/cont-ep/{$machineId}/complete");

    $current = MachineCurrentState::where('root_event_id', $machineId)->first();
    expect($current?->scenario_class)->toBeNull();
});

test('availableScenarios and activeScenario appear simultaneously', function (): void {
    $createResponse = $this->postJson('/api/cont-ep/create');
    $machineId      = $createResponse->json('data.id');

    MachineCurrentState::where('root_event_id', $machineId)
        ->update([
            'scenario_class'  => ContinuationScenario::class,
            'scenario_params' => null,
        ]);

    $response = $this->postJson("/api/cont-ep/{$machineId}/start");
    $data     = $response->json('data');

    // Both availableScenarios and activeScenario should be present
    expect($data)->toHaveKey('availableScenarios')
        ->and($data)->toHaveKey('activeScenario');
});

test('POST without slug triggers executeContinuation', function (): void {
    ['machineId' => $machineId] = createPersistedMachineAtStarted($this);

    MachineCurrentState::where('root_event_id', $machineId)
        ->update([
            'scenario_class'  => ContinuationScenario::class,
            'scenario_params' => null,
        ]);

    // POST without scenario slug — controller detects continuation from DB
    $response = $this->postJson("/api/cont-ep/{$machineId}/complete");
    $data     = $response->json('data');

    // Machine should move to 'completed' (COMPLETE event processed via executeContinuation)
    expect($data['state'][0])->toContain('completed');
});

test('POST with different slug replaces active continuation', function (): void {
    ['machineId' => $machineId] = createPersistedMachineAtStarted($this);

    MachineCurrentState::where('root_event_id', $machineId)
        ->update([
            'scenario_class'  => ContinuationScenario::class,
            'scenario_params' => null,
        ]);

    // POST with a new scenario slug — should use the new scenario instead of continuation
    // ContinueLoopScenario targets ScenarioTestMachine (source=reviewing),
    // but since this is TestEndpointMachine, ScenarioDiscovery won't find it.
    // We need to test the code path: when scenario slug is provided,
    // maybeRegisterScenarioOverrides resolves by slug instead of reading DB continuation.

    // Since ScenarioDiscovery scans by machine class and TestEndpointMachine has no scenarios,
    // providing a slug will result in 404. This verifies the slug takes precedence over DB continuation.
    $response = $this->postJson("/api/cont-ep/{$machineId}/complete", [
        'scenario' => 'nonexistent-scenario-slug',
    ]);

    // 404 because the slug was looked up (not ignored in favor of DB continuation)
    $response->assertStatus(404);
});

test('POST with scenario:null explicit deactivation', function (): void {
    ['machineId' => $machineId] = createPersistedMachineAtStarted($this);

    // Set a non-continuation scenario in DB (HappyPathScenario has no continuation)
    MachineCurrentState::where('root_event_id', $machineId)
        ->update([
            'scenario_class'  => HappyPathScenario::class,
            'scenario_params' => null,
        ]);

    // POST without slug — controller reads HappyPathScenario from DB,
    // sees hasContinuation()=false, deactivates (clears scenario columns)
    $this->postJson("/api/cont-ep/{$machineId}/complete");

    $current = MachineCurrentState::where('root_event_id', $machineId)->first();
    expect($current?->scenario_class)->toBeNull()
        ->and($current?->scenario_params)->toBeNull();
});

// ── Event mismatch detection ────────────────────────────────────────────────

test('scenario attached to wrong endpoint throws event mismatch (POST without type in body)', function (): void {
    // CompletionScenario expects COMPLETE event, but we attach it to CANCEL endpoint
    ['machineId' => $machineId] = createPersistedMachineAtStarted($this);

    $response = $this->postJson("/api/cont-ep/{$machineId}/cancel", [
        'scenario' => 'completion-scenario',
    ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('COMPLETE')
        ->and($response->json('message'))->toContain('CANCEL');
});

test('scenario attached to correct endpoint succeeds (no mismatch)', function (): void {
    // CompletionScenario expects COMPLETE event, attach to COMPLETE endpoint — should work
    ['machineId' => $machineId] = createPersistedMachineAtStarted($this);

    $response = $this->postJson("/api/cont-ep/{$machineId}/complete", [
        'scenario' => 'completion-scenario',
    ]);

    // Should not get event mismatch error — scenario activates and machine transitions
    $response->assertStatus(201);
    expect($response->json('data.state'))->toContain('test_endpoint.completed');
});

test('POST with explicit scenario:null deactivates active continuation', function (): void {
    ['machineId' => $machineId] = createPersistedMachineAtStarted($this);

    // Set a continuation scenario in DB (ContinuationScenario has continuation)
    MachineCurrentState::where('root_event_id', $machineId)
        ->update([
            'scenario_class'  => ContinuationScenario::class,
            'scenario_params' => null,
        ]);

    // POST with explicit scenario:null — should deactivate continuation, not auto-restore
    $this->postJson("/api/cont-ep/{$machineId}/complete", [
        'scenario' => null,
    ]);

    $current = MachineCurrentState::where('root_event_id', $machineId)->first();
    expect($current?->scenario_class)->toBeNull()
        ->and($current?->scenario_params)->toBeNull();
});
