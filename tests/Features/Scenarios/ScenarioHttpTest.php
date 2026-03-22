<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tarfinlabs\EventMachine\Scenarios\ScenarioController;

beforeEach(function (): void {
    config([
        'machine.scenarios.enabled' => true,
        'machine.scenarios.path'    => __DIR__.'/../../Stubs/Scenarios',
    ]);

    // Register routes manually since the service provider boots before config is set
    Route::prefix('machine/scenarios')->group(function (): void {
        Route::get('/', [ScenarioController::class, 'list']);
        Route::post('/{scenario}', [ScenarioController::class, 'play']);
        Route::get('/{scenario}/describe', [ScenarioController::class, 'describe']);
    });
});

it('returns scenario list via GET /machine/scenarios', function (): void {
    $response = $this->getJson('/machine/scenarios');

    $response->assertOk();
    $response->assertJsonStructure(['scenarios']);
});

it('returns 404 for unknown scenario', function (): void {
    $response = $this->postJson('/machine/scenarios/nonexistent-scenario');

    $response->assertNotFound();
    $response->assertJsonStructure(['error']);
});

it('describes a scenario via GET /machine/scenarios/{slug}/describe', function (): void {
    $response = $this->getJson('/machine/scenarios/nonexistent/describe');

    $response->assertNotFound();
});
