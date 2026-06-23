<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Routing\MachineRouter;
use Tarfinlabs\EventMachine\Services\ArchiveService;
use Tarfinlabs\EventMachine\Exceptions\InvalidRouterConfigException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Reads\ReadsMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\GetEndpoint\GetEndpointMachine;

beforeEach(function (): void {
    MachineRouter::register(ReadsMachine::class, [
        'prefix' => 'api/reads',
        'name'   => 'reads',
        'reads'  => [
            'status'  => null,
            'fields'  => ['output' => ['orderId', 'total']],
            'summary' => ['output' => 'readSummaryOutput'],
            'quiet'   => ['available_events' => false],
        ],
    ]);

    // A second machine class, used to exercise the wrong-machine-class 404.
    MachineRouter::register(GetEndpointMachine::class, [
        'prefix' => 'api/other',
        'name'   => 'other',
        'reads'  => ['status' => null],
    ]);

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
});

function makeReadsInstance(): string
{
    $machine = ReadsMachine::create();
    $machine->persist();

    return $machine->state->history->first()->root_event_id;
}

// === Registration ===

test('reads => true registers a default GET status route', function (): void {
    MachineRouter::register(ReadsMachine::class, ['prefix' => 'api/r2', 'name' => 'r2', 'reads' => true]);
    Route::getRoutes()->refreshNameLookups();

    $route = Route::getRoutes()->getByName('r2.read.status');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('api/r2/{machineId}/status')
        ->and($route->methods())->toContain('GET');
});

test('associative reads register one route per entry', function (): void {
    expect(Route::getRoutes()->getByName('reads.read.status'))->not->toBeNull()
        ->and(Route::getRoutes()->getByName('reads.read.fields'))->not->toBeNull()
        ->and(Route::getRoutes()->getByName('reads.read.summary'))->not->toBeNull();
});

test('a reads-only registration (no endpoints) still registers routes', function (): void {
    // ReadsMachine declares no endpoints; the routes above prove the reads-only path works.
    expect(Route::getRoutes()->getByName('reads.read.status'))->not->toBeNull();
});

test('a read colliding with a GET machineId-bound endpoint throws', function (): void {
    MachineRouter::register(GetEndpointMachine::class, [
        'prefix'       => 'api/collide',
        'machineIdFor' => ['STATUS_REQUESTED'], // GET /status, machineId-bound
        'reads'        => ['status' => null],   // GET /status → collision
    ]);
})->throws(InvalidRouterConfigException::class);

test('duplicate read URIs throw', function (): void {
    MachineRouter::register(ReadsMachine::class, [
        'prefix' => 'api/dup',
        'reads'  => ['status' => null, 'alias' => ['uri' => 'status']],
    ]);
})->throws(InvalidRouterConfigException::class);

// === Snapshot feature ===

test('a read returns the envelope with state, availableEvents and isProcessing', function (): void {
    $id = makeReadsInstance();

    $response = $this->getJson("/api/reads/{$id}/status");

    $response->assertStatus(200);
    expect($response->json('data.state'))->toBe(['reads_machine.pending'])
        ->and($response->json('data.availableEvents'))->not->toBeNull()
        ->and($response->json('data.isProcessing'))->toBeFalse();
});

test('a read is zero-write — no new machine_events and no lock row', function (): void {
    $id = makeReadsInstance();

    $before = MachineEvent::count();
    $this->getJson("/api/reads/{$id}/status")->assertStatus(200);
    $after = MachineEvent::count();

    expect($after)->toBe($before);
    expect(DB::table('machine_locks')->count())->toBe(0);
});

// === Output shaping ===

test('array output filters context keys (outputKeys path)', function (): void {
    $id = makeReadsInstance();

    $response = $this->getJson("/api/reads/{$id}/fields");

    $response->assertStatus(200);
    expect($response->json('data.output'))->toBe(['orderId' => 'ORD-1', 'total' => 100]);
});

test('OutputBehavior output shapes via the outputKey path', function (): void {
    $id = makeReadsInstance();

    $response = $this->getJson("/api/reads/{$id}/summary");

    $response->assertStatus(200);
    expect($response->json('data.output.summary'))->toBe('ORD-1:100');
});

test('available_events => false omits the availableEvents key', function (): void {
    $id = makeReadsInstance();

    $response = $this->getJson("/api/reads/{$id}/quiet");

    $response->assertStatus(200);
    expect($response->json('data'))->not->toHaveKey('availableEvents');
});

// === Errors ===

test('an unknown machineId returns 404', function (): void {
    $this->getJson('/api/reads/01HZZZZZZZZZZZZZZZZZZZZZZZ/status')->assertStatus(404);
});

test('a machineId belonging to a different machine class returns 404', function (): void {
    $id = makeReadsInstance();

    // Restoring a ReadsMachine id under GetEndpointMachine → idMap miss → 404.
    $this->getJson("/api/other/{$id}/status")->assertStatus(404);
});

test('a read of an archived machine restores transparently and returns 200', function (): void {
    config([
        'machine.archival.enabled'                => true,
        'machine.archival.level'                  => 6,
        'machine.archival.days_inactive'          => 30,
        'machine.archival.restore_cooldown_hours' => 24,
    ]);

    $id = makeReadsInstance();

    app(ArchiveService::class)->archiveMachine($id);
    expect(MachineEvent::where('root_event_id', $id)->count())->toBe(0); // moved to the archive

    $response = $this->getJson("/api/reads/{$id}/status");

    $response->assertStatus(200);
    expect($response->json('data.state'))->toBe(['reads_machine.pending']);
});
