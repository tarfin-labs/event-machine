<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Tests\Stubs\Models\ModelA;
use Tarfinlabs\EventMachine\Tests\Stubs\Models\ModelB;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AbcMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ElevatorMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

// ============================================================================
// Helper: count machine_events queries in a callback
// ============================================================================

function countMachineQueries(Closure $callback): int
{
    DB::enableQueryLog();
    DB::flushQueryLog();

    $callback();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    return collect($queries)
        ->filter(fn (array $q) => str_contains($q['query'], 'machine_events'))
        ->count();
}

// ============================================================================
// Lazy Loading — Single Model
// ============================================================================

it('does NOT query machine_events when loading a model', function (): void {
    $modelA = ModelA::create(['value' => 'test']);

    $queryCount = countMachineQueries(function () use ($modelA): void {
        ModelA::find($modelA->id);
    });

    expect($queryCount)->toBe(0);
});

it('does NOT query machine_events when accessing non-machine attributes', function (): void {
    $modelA = ModelA::create(['value' => 'test']);

    $queryCount = countMachineQueries(function () use ($modelA): void {
        $loaded = ModelA::find($modelA->id);
        $_ = $loaded->value;
    });

    expect($queryCount)->toBe(0);
});

it('does NOT trigger restore when accessing machine attribute without property access', function (): void {
    $modelA = ModelA::create(['value' => 'test']);

    $queryCount = countMachineQueries(function () use ($modelA): void {
        $loaded = ModelA::find($modelA->id);
        $machine = $loaded->abc_mre;

        expect($machine)->toBeInstanceOf(Machine::class);
    });

    expect($queryCount)->toBe(0);
});

it('triggers exactly ONE restore query on first property access', function (): void {
    $modelA = ModelA::create(['value' => 'test']);

    $queryCount = countMachineQueries(function () use ($modelA): void {
        $loaded = ModelA::find($modelA->id);
        $_ = $loaded->abc_mre->state;
    });

    expect($queryCount)->toBe(1);
});

it('triggers zero additional queries on second access (caching)', function (): void {
    $modelA = ModelA::create(['value' => 'test']);
    $loaded = ModelA::find($modelA->id);

    // First access initializes
    $_ = $loaded->abc_mre->state;

    // Second access should use cache
    $queryCount = countMachineQueries(function () use ($loaded): void {
        $_ = $loaded->abc_mre->state;
        $loaded->abc_mre->send(['type' => 'INCREASE']);
    });

    expect($queryCount)->toBe(0);
});

// ============================================================================
// Lazy Loading — Collections
// ============================================================================

it('does NOT query machine_events when loading a collection', function (): void {
    ModelA::create(['value' => 'a']);
    ModelA::create(['value' => 'b']);
    ModelA::create(['value' => 'c']);

    $queryCount = countMachineQueries(function (): void {
        ModelA::all();
    });

    expect($queryCount)->toBe(0);
});

it('does NOT query machine_events when iterating collection without machine access', function (): void {
    ModelA::create(['value' => 'a']);
    ModelA::create(['value' => 'b']);

    $queryCount = countMachineQueries(function (): void {
        ModelA::all()->each(fn (ModelA $m) => $m->value);
    });

    expect($queryCount)->toBe(0);
});

it('triggers exactly N queries when iterating collection WITH machine access', function (): void {
    ModelA::create(['value' => 'a']);
    ModelA::create(['value' => 'b']);
    ModelA::create(['value' => 'c']);

    $queryCount = countMachineQueries(function (): void {
        ModelA::all()->each(fn (ModelA $m) => $m->abc_mre->state);
    });

    expect($queryCount)->toBe(3);
});

// ============================================================================
// Serialization
// ============================================================================

it('returns root_event_id string in toArray() with zero machine queries', function (): void {
    $modelA = ModelA::create(['value' => 'test']);

    $queryCount = countMachineQueries(function () use ($modelA): void {
        $loaded = ModelA::find($modelA->id);
        $array = $loaded->toArray();

        expect($array['abc_mre'])->toBeString();
        expect($array['abc_mre'])->toBe($loaded->getRawOriginal('abc_mre'));
    });

    expect($queryCount)->toBe(0);
});

it('returns root_event_id strings in collection toArray() with zero machine queries', function (): void {
    ModelA::create(['value' => 'a']);
    ModelA::create(['value' => 'b']);

    $queryCount = countMachineQueries(function (): void {
        $array = ModelA::all()->toArray();

        expect($array)->toHaveCount(2);
        expect($array[0]['abc_mre'])->toBeString();
    });

    expect($queryCount)->toBe(0);
});

it('returns ULID string in toArray() even after machine was accessed', function (): void {
    $modelA = ModelA::create(['value' => 'test']);
    $loaded = ModelA::find($modelA->id);

    // Initialize the machine
    $_ = $loaded->abc_mre->state;

    // toArray() should still return the raw ULID
    $array = $loaded->toArray();

    expect($array['abc_mre'])->toBeString();
    expect($array['abc_mre'])->toBe($loaded->getRawOriginal('abc_mre'));
});

// ============================================================================
// mergeAttributesFromCachedCasts edge case
// ============================================================================

it('does NOT trigger machine restore when saving after toArray()', function (): void {
    $modelA = ModelA::create(['value' => 'test']);
    $loaded = ModelA::find($modelA->id);

    // toArray() caches the lazy proxy in classCastCache
    $loaded->toArray();

    // save() calls mergeAttributesFromCachedCasts() which calls set()
    // set() should detect the uninitialized proxy and return raw ULID
    $queryCount = countMachineQueries(function () use ($loaded): void {
        $loaded->value = 'updated';
        $loaded->save();
    });

    expect($queryCount)->toBe(0);
});

it('extracts root_event_id from initialized proxy on save', function (): void {
    $modelA = ModelA::create(['value' => 'test']);
    $loaded = ModelA::find($modelA->id);

    // Initialize the machine
    $_ = $loaded->abc_mre->state;

    // save() should extract root_event_id from the initialized proxy
    $loaded->value = 'updated';
    $loaded->save();

    expect($loaded->getRawOriginal('abc_mre'))->not->toBeNull();
});

// ============================================================================
// Refresh
// ============================================================================

it('refreshMachine() clears cache and creates fresh proxy', function (): void {
    $modelA = ModelA::create(['value' => 'test']);
    $loaded = ModelA::find($modelA->id);

    // Initialize
    $_ = $loaded->abc_mre->state;

    // Refresh should clear cache; next property access triggers fresh restore
    $queryCount = countMachineQueries(function () use ($loaded): void {
        $loaded->refreshMachine('abc_mre')->state;
    });

    expect($queryCount)->toBe(1);
});

it('Machine::refresh() re-restores state in place', function (): void {
    $modelA = ModelA::create(['value' => 'test']);
    $loaded = ModelA::find($modelA->id);

    // Initialize
    $machine = $loaded->abc_mre;
    $_ = $machine->state;

    // refresh() should re-query DB
    $queryCount = countMachineQueries(function () use ($machine): void {
        $machine->refresh();
    });

    expect($queryCount)->toBe(1);
});

// ============================================================================
// Helpers
// ============================================================================

it('hasMachine() returns true/false without triggering machine load', function (): void {
    $modelA = ModelA::create(['value' => 'test']);
    $loaded = ModelA::find($modelA->id);

    $queryCount = countMachineQueries(function () use ($loaded): void {
        expect($loaded->hasMachine('abc_mre'))->toBeTrue();
        expect($loaded->hasMachine('nonexistent'))->toBeFalse();
    });

    expect($queryCount)->toBe(0);
});

it('getMachineId() returns raw ULID string without triggering load', function (): void {
    $modelA = ModelA::create(['value' => 'test']);
    $loaded = ModelA::find($modelA->id);

    $queryCount = countMachineQueries(function () use ($loaded): void {
        $id = $loaded->getMachineId('abc_mre');
        expect($id)->toBeString();
        expect($id)->toBe($loaded->getRawOriginal('abc_mre'));
    });

    expect($queryCount)->toBe(0);
});

// ============================================================================
// Auto-initialization
// ============================================================================

it('auto-initializes machines on model creation', function (): void {
    $modelA = ModelA::create(['value' => 'test']);

    expect($modelA->getRawOriginal('abc_mre'))->not->toBeNull();
    expect($modelA->getRawOriginal('traffic_mre'))->not->toBeNull();
    expect($modelA->getRawOriginal('elevator_mre'))->not->toBeNull();
});

it('preserves explicit root_event_id on creation', function (): void {
    $machine = AbcMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $modelA = ModelA::create([
        'value'   => 'test',
        'abc_mre' => $rootEventId,
    ]);

    expect($modelA->getRawOriginal('abc_mre'))->toBe($rootEventId);
});

// ============================================================================
// Multiple machines
// ============================================================================

it('supports multiple independent machines on one model', function (): void {
    $modelA = ModelA::create(['value' => 'test']);
    $loaded = ModelA::find($modelA->id);

    expect($loaded->abc_mre)->toBeInstanceOf(Machine::class);
    expect($loaded->traffic_mre)->toBeInstanceOf(Machine::class);
    expect($loaded->elevator_mre)->toBeInstanceOf(Machine::class);
});

it('accessing one machine does NOT initialize others', function (): void {
    $modelA = ModelA::create(['value' => 'test']);
    $loaded = ModelA::find($modelA->id);

    // Initialize only abc_mre
    $_ = $loaded->abc_mre->state;

    // traffic_mre access alone should NOT trigger restore
    $queryCount = countMachineQueries(function () use ($loaded): void {
        $machine = $loaded->traffic_mre;
        // Only proxy access, no property/method → zero queries
        expect($machine)->toBeInstanceOf(Machine::class);
    });

    expect($queryCount)->toBe(0);
});

// ============================================================================
// Polymorphic casting
// ============================================================================

it('resolves correct machine class via method-based resolver', function (): void {
    $abcMachine = AbcMachine::create();
    $abcMachine->persist();

    $modelB = ModelB::create([
        'value'        => 'test',
        'machine_type' => 'default',
        'status_mre'   => $abcMachine->state->history->first()->root_event_id,
    ]);

    $loaded = ModelB::find($modelB->id);

    // Default resolver returns AbcMachine
    expect($loaded->status_mre)->toBeInstanceOf(Machine::class);
    expect($loaded->status_mre->state)->not->toBeNull();
});

it('resolves different machine class based on resolver attribute', function (): void {
    $elevatorMachine = ElevatorMachine::create();
    $elevatorMachine->persist();

    $modelB = ModelB::create([
        'value'        => 'test',
        'machine_type' => 'elevator',
        'status_mre'   => $elevatorMachine->state->history->first()->root_event_id,
    ]);

    $loaded = ModelB::find($modelB->id);

    expect($loaded->status_mre)->toBeInstanceOf(Machine::class);
    expect($loaded->status_mre->state)->not->toBeNull();
});

it('does NOT auto-initialize polymorphic machines on creation', function (): void {
    $modelB = ModelB::create([
        'value'        => 'test',
        'machine_type' => 'default',
    ]);

    // PolymorphicMachineCast is not a Machine subclass, so bootHasMachines skips it
    expect($modelB->getRawOriginal('status_mre'))->toBeNull();
});

// ============================================================================
// Edge cases
// ============================================================================

it('returns null for machine attribute with null value', function (): void {
    $modelA = ModelA::create(['value' => 'test']);
    $loaded = ModelA::find($modelA->id);

    // Force null by direct DB update
    DB::table('model_a_s')->where('id', $loaded->id)->update(['abc_mre' => null]);
    $loaded = ModelA::find($modelA->id);

    expect($loaded->abc_mre)->toBeNull();
});

it('instanceof Machine is true on uninitialized lazy proxy', function (): void {
    $modelA = ModelA::create(['value' => 'test']);
    $loaded = ModelA::find($modelA->id);

    // Access attribute but don't access properties
    $machine = $loaded->abc_mre;

    expect($machine)->toBeInstanceOf(Machine::class);
});

it('Machine::fake() works through lazy proxy', function (): void {
    AbcMachine::fake();

    $modelA = ModelA::create(['value' => 'test']);
    $loaded = ModelA::find($modelA->id);

    // The lazy proxy factory calls AbcMachine::create() which returns faked stub
    $machine = $loaded->abc_mre;
    expect($machine)->toBeInstanceOf(Machine::class);

    AbcMachine::clearFake();
});

it('$model->fresh() returns model with fresh lazy proxies', function (): void {
    $modelA = ModelA::create(['value' => 'test']);

    // Initialize machine
    $_ = $modelA->abc_mre->state;

    // fresh() returns a new model instance — cast cache should be empty
    $fresh = $modelA->fresh();

    // Accessing machine on fresh model should not trigger extra queries
    // (it creates a new lazy proxy)
    $queryCount = countMachineQueries(function () use ($fresh): void {
        $machine = $fresh->abc_mre;
        expect($machine)->toBeInstanceOf(Machine::class);
    });

    expect($queryCount)->toBe(0);
});
