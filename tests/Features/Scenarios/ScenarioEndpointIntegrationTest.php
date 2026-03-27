<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tarfinlabs\EventMachine\Routing\MachineRouter;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Scenarios\MidFlightMachine;

beforeEach(function (): void {
    config(['machine.scenarios.enabled' => true]);
    config(['machine.scenarios.path' => realpath(__DIR__.'/../../Stubs/Scenarios')]);

    MachineRouter::register(MidFlightMachine::class, [
        'prefix'       => '/api/mid-flight',
        'name'         => 'mid_flight',
        'create'       => true,
        'machineIdFor' => ['ACTIVATE', 'FINISH'],
    ]);

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
});

it('includes available_scenarios in endpoint response after transition', function (): void {
    // Create machine (idle state)
    $createResponse = $this->postJson('/api/mid-flight/create');
    $createResponse->assertStatus(201);
    $machineId = $createResponse->json('data.machine_id');

    // Send ACTIVATE — machine goes idle → active
    $response = $this->postJson("/api/mid-flight/{$machineId}/activate", [
        'type' => 'ACTIVATE',
    ]);

    $response->assertStatus(200);
    $data = $response->json('data');

    // After transition, state is 'active'
    expect($data['value'])->toContain('mid_flight.active');

    // available_scenarios should list scenarios with from='active'
    // MidFlightFinishScenario has from()='active'
    expect($data)->toHaveKey('available_scenarios');
    expect($data['available_scenarios'])->toBeArray();

    $slugs = array_column($data['available_scenarios'], 'slug');
    expect($slugs)->toContain('mid-flight-finish-scenario');
});

it('omits available_scenarios when scenarios are disabled', function (): void {
    config(['machine.scenarios.enabled' => false]);

    $createResponse = $this->postJson('/api/mid-flight/create');
    $machineId      = $createResponse->json('data.machine_id');

    $response = $this->postJson("/api/mid-flight/{$machineId}/activate", [
        'type' => 'ACTIVATE',
    ]);

    $data = $response->json('data');

    expect($data)->not->toHaveKey('available_scenarios');
});

it('returns empty available_scenarios when no scenarios match current state', function (): void {
    $createResponse = $this->postJson('/api/mid-flight/create');
    $machineId      = $createResponse->json('data.machine_id');

    // Send ACTIVATE then FINISH — machine reaches 'done' (final)
    $this->postJson("/api/mid-flight/{$machineId}/activate", ['type' => 'ACTIVATE']);
    $response = $this->postJson("/api/mid-flight/{$machineId}/finish", ['type' => 'FINISH']);

    $data = $response->json('data');

    // No scenarios have from()='done', so available_scenarios should be empty
    expect($data['available_scenarios'])->toBe([]);
});

it('plays scenario continuation when scenario field is in event request', function (): void {
    $createResponse = $this->postJson('/api/mid-flight/create');
    $machineId      = $createResponse->json('data.machine_id');

    // Send ACTIVATE with scenario continuation — should activate then finish
    $response = $this->postJson("/api/mid-flight/{$machineId}/activate", [
        'type'     => 'ACTIVATE',
        'scenario' => 'mid-flight-finish-scenario',
    ]);

    $response->assertStatus(200);
    $data = $response->json('data');

    // Machine should be at 'done' — event transitioned to 'active', then scenario finished to 'done'
    expect($data['value'])->toContain('mid_flight.done');
});

it('ignores scenario field when scenarios are disabled', function (): void {
    config(['machine.scenarios.enabled' => false]);

    $createResponse = $this->postJson('/api/mid-flight/create');
    $machineId      = $createResponse->json('data.machine_id');

    // Send ACTIVATE with scenario — should be ignored since disabled
    $response = $this->postJson("/api/mid-flight/{$machineId}/activate", [
        'type'     => 'ACTIVATE',
        'scenario' => 'mid-flight-finish-scenario',
    ]);

    $response->assertStatus(200);
    $data = $response->json('data');

    // Machine should be at 'active' — scenario was ignored
    expect($data['value'])->toContain('mid_flight.active');
});
