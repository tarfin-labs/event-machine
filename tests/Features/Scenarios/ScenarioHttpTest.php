<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tarfinlabs\EventMachine\Routing\MachineRouter;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Scenarios\MidFlightMachine;

beforeEach(function (): void {
    config([
        'machine.scenarios.enabled' => true,
        'machine.scenarios.path'    => realpath(__DIR__.'/../../Stubs/Scenarios'),
    ]);

    MachineRouter::register(MidFlightMachine::class, [
        'prefix'       => '/api/mid-flight',
        'name'         => 'mid_flight',
        'create'       => true,
        'machineIdFor' => ['ACTIVATE', 'FINISH'],
    ]);

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
});

it('returns scenario list via GET {prefix}/scenarios', function (): void {
    $response = $this->getJson('/api/mid-flight/scenarios');

    $response->assertOk();
    $response->assertJsonStructure(['scenarios']);

    $scenarios = $response->json('scenarios');
    expect($scenarios)->toHaveKey('MidFlightMachine');
});

it('describes a scenario via GET {prefix}/scenarios/{slug}/describe', function (): void {
    $response = $this->getJson('/api/mid-flight/scenarios/mid-flight-finish-scenario/describe');

    $response->assertOk();
    $response->assertJsonFragment([
        'from' => 'active',
    ]);
});

it('returns 404 for unknown scenario', function (): void {
    $response = $this->postJson('/api/mid-flight/scenarios/nonexistent-scenario');

    $response->assertNotFound();
    $response->assertJsonStructure(['error']);
});

it('plays a scenario via POST {prefix}/scenarios/{slug}', function (): void {
    $response = $this->postJson('/api/mid-flight/scenarios/mid-flight-to-active-scenario');

    $response->assertOk();
    $response->assertJsonFragment([
        'current_state' => 'active',
    ]);
});

it('plays mid-flight scenario via POST {prefix}/scenarios/{slug}/{machineId}', function (): void {
    // First create a machine at 'active' state
    $playResponse = $this->postJson('/api/mid-flight/scenarios/mid-flight-to-active-scenario');
    $rootEventId  = $playResponse->json('root_event_id');

    // Play mid-flight scenario on existing machine
    $response = $this->postJson("/api/mid-flight/scenarios/mid-flight-finish-scenario/{$rootEventId}");

    $response->assertOk();
    $response->assertJsonFragment([
        'current_state' => 'done',
    ]);
});
