<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Routing\MachineRouter;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ComputedContextEndpointMachine;

beforeEach(function (): void {
    MachineRouter::register(ComputedContextEndpointMachine::class, [
        'prefix'       => '/api/computed',
        'create'       => true,
        'machineIdFor' => ['START', 'COMPLETE'],
    ]);

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
});

test('endpoint response includes computed context values', function (): void {
    $createResponse = $this->postJson('/api/computed/create');
    $createResponse->assertStatus(201);
    $machineId = $createResponse->json('data.machine_id');

    $response = $this->postJson("/api/computed/{$machineId}/start");

    $response->assertOk();

    $context = $response->json('data.context');

    expect($context)->toHaveKeys(['count', 'status', 'isCountEven', 'displayLabel'])
        ->and($context['count'])->toBe(1)
        ->and($context['isCountEven'])->toBeFalse()
        ->and($context['displayLabel'])->toBe('Item #1 (active)');
});

test('contextKeys filtering includes computed values', function (): void {
    $createResponse = $this->postJson('/api/computed/create');
    $machineId      = $createResponse->json('data.machine_id');

    $this->postJson("/api/computed/{$machineId}/start");

    // COMPLETE endpoint has contextKeys: ['count', 'isCountEven']
    $response = $this->postJson("/api/computed/{$machineId}/complete");

    $response->assertOk();

    $context = $response->json('data.context');

    expect($context)->toHaveKeys(['count', 'isCountEven'])
        ->and($context)->not->toHaveKeys(['status', 'displayLabel']);
});

test('contextKeys filtering excludes computed values', function (): void {
    $createResponse = $this->postJson('/api/computed/create');
    $machineId      = $createResponse->json('data.machine_id');

    $this->postJson("/api/computed/{$machineId}/start");

    // COMPLETE endpoint has contextKeys: ['count', 'isCountEven']
    $response = $this->postJson("/api/computed/{$machineId}/complete");

    $context = $response->json('data.context');

    expect($context)->not->toHaveKey('displayLabel');
});

test('State toArray includes computed context', function (): void {
    $machine = ComputedContextEndpointMachine::create();
    $state   = $machine->send(['type' => 'START']);

    $stateArray = $state->toArray();

    expect($stateArray['context'])->toHaveKeys(['count', 'status', 'isCountEven', 'displayLabel'])
        ->and($stateArray['context']['isCountEven'])->toBeFalse()
        ->and($stateArray['context']['count'])->toBe(1);
});

test('DB persisted context excludes computed values', function (): void {
    $machine = ComputedContextEndpointMachine::create();
    $machine->send(['type' => 'START']);
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;

    $lastEvent = MachineEvent::where('root_event_id', $rootEventId)
        ->orderBy('sequence_number', 'desc')
        ->first();

    $persistedContext = $lastEvent->context;

    expect($persistedContext)->toHaveKeys(['count', 'status'])
        ->and($persistedContext)->not->toHaveKeys(['isCountEven', 'displayLabel']);
});
