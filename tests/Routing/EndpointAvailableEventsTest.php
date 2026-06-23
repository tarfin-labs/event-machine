<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tarfinlabs\EventMachine\Routing\MachineRouter;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Reads\QuietEndpointMachine;

beforeEach(function (): void {
    MachineRouter::register(QuietEndpointMachine::class, [
        'prefix' => 'api/quiet',
        'name'   => 'quiet',
    ]);

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
});

test('an endpoint with available_events => false omits the availableEvents key', function (): void {
    $response = $this->postJson('/api/quiet/go');

    $response->assertStatus(200);
    expect($response->json('data'))->not->toHaveKey('availableEvents');
});
