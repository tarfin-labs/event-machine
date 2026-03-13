<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tarfinlabs\EventMachine\Routing\MachineRouter;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestCancelEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestEndpointAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestNoEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestMiddlewareEndpointMachine;

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
|
| After registering routes via MachineRouter, the internal name-lookup
| table must be rebuilt so getByName() works.
*/

function refreshRoutes(): void
{
    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
}

// ─── Route Registration ───────────────────────────────────────────────

test('register creates routes for all parsed endpoints', function (): void {
    MachineRouter::register(TestEndpointMachine::class, [
        'prefix'    => '/api/test',
        'model'     => 'App\\Models\\Order',
        'attribute' => 'machine',
    ]);

    refreshRoutes();

    $routes = Route::getRoutes();

    expect($routes->getByName('test_endpoint.start'))->not->toBeNull()
        ->and($routes->getByName('test_endpoint.complete'))->not->toBeNull()
        ->and($routes->getByName('test_endpoint.cancel'))->not->toBeNull();
});

// ─── Route Naming ─────────────────────────────────────────────────────

test('routes follow namePrefix.event_type_lowercase naming pattern', function (): void {
    MachineRouter::register(TestEndpointMachine::class, [
        'prefix'    => '/api/naming',
        'model'     => 'App\\Models\\Order',
        'attribute' => 'machine',
    ]);

    refreshRoutes();

    $routes = Route::getRoutes();

    // Default namePrefix comes from definition id = 'test_endpoint'
    expect($routes->getByName('test_endpoint.start'))->not->toBeNull()
        ->and($routes->getByName('test_endpoint.complete'))->not->toBeNull()
        ->and($routes->getByName('test_endpoint.cancel'))->not->toBeNull();
});

test('custom name prefix overrides default machine id', function (): void {
    MachineRouter::register(TestEndpointMachine::class, [
        'prefix' => '/api/custom-name',
        'name'   => 'my_custom',
    ]);

    refreshRoutes();

    $routes = Route::getRoutes();

    expect($routes->getByName('my_custom.start'))->not->toBeNull()
        ->and($routes->getByName('my_custom.complete'))->not->toBeNull()
        ->and($routes->getByName('my_custom.cancel'))->not->toBeNull();
});

// ─── Handler Selection: Model-Bound ───────────────────────────────────

test('model-bound routes use handleModelBound handler', function (): void {
    MachineRouter::register(TestEndpointMachine::class, [
        'prefix'    => '/api/model-bound',
        'model'     => 'App\\Models\\Order',
        'attribute' => 'machine',
    ]);

    refreshRoutes();

    $routes = Route::getRoutes();
    $route  = $routes->getByName('test_endpoint.start');

    expect($route)->not->toBeNull()
        ->and($route->getActionMethod())->toBe('handleModelBound');
});

test('model-bound route URI includes model parameter', function (): void {
    MachineRouter::register(TestEndpointMachine::class, [
        'prefix'    => '/api/model-uri',
        'model'     => 'App\\Models\\Order',
        'attribute' => 'machine',
    ]);

    refreshRoutes();

    $routes = Route::getRoutes();
    $route  = $routes->getByName('test_endpoint.start');

    // Model param is camelCase of class basename: 'Order' -> 'order'
    expect($route->uri())->toBe('api/model-uri/{order}/start');
});

test('model-bound routes register explicit Route::model binding', function (): void {
    MachineRouter::register(TestEndpointMachine::class, [
        'prefix'    => '/api/model-binding',
        'model'     => 'App\\Models\\Order',
        'attribute' => 'machine',
    ]);

    refreshRoutes();

    // Route::model() registers a binding callback for the parameter name
    $binder = app('router')->getBindingCallback('order');

    expect($binder)->not->toBeNull();
});

// ─── Handler Selection: MachineId-Bound ───────────────────────────────

test('machineIdFor events use handleMachineIdBound handler', function (): void {
    MachineRouter::register(TestEndpointMachine::class, [
        'prefix'       => '/api/machine-id',
        'model'        => 'App\\Models\\Order',
        'attribute'    => 'machine',
        'machineIdFor' => ['CANCEL'],
    ]);

    refreshRoutes();

    $routes = Route::getRoutes();
    $route  = $routes->getByName('test_endpoint.cancel');

    expect($route)->not->toBeNull()
        ->and($route->getActionMethod())->toBe('handleMachineIdBound');
});

test('machineIdFor route URI includes machineId parameter', function (): void {
    MachineRouter::register(TestEndpointMachine::class, [
        'prefix'       => '/api/machine-id-uri',
        'model'        => 'App\\Models\\Order',
        'attribute'    => 'machine',
        'machineIdFor' => ['CANCEL'],
    ]);

    refreshRoutes();

    $routes = Route::getRoutes();
    $route  = $routes->getByName('test_endpoint.cancel');

    expect($route->uri())->toBe('api/machine-id-uri/{machineId}/cancel');
});

test('machineIdFor accepts event class keys', function (): void {
    MachineRouter::register(TestEndpointMachine::class, [
        'prefix'       => '/api/machine-id-class',
        'model'        => 'App\\Models\\Order',
        'attribute'    => 'machine',
        'machineIdFor' => [TestCancelEvent::class],
    ]);

    refreshRoutes();

    $routes = Route::getRoutes();
    $route  = $routes->getByName('test_endpoint.cancel');

    expect($route)->not->toBeNull()
        ->and($route->getActionMethod())->toBe('handleMachineIdBound')
        ->and($route->uri())->toBe('api/machine-id-class/{machineId}/cancel');
});

test('non-machineIdFor events still use handleModelBound', function (): void {
    MachineRouter::register(TestEndpointMachine::class, [
        'prefix'       => '/api/mixed',
        'model'        => 'App\\Models\\Order',
        'attribute'    => 'machine',
        'machineIdFor' => ['CANCEL'],
    ]);

    refreshRoutes();

    $routes = Route::getRoutes();

    expect($routes->getByName('test_endpoint.start')->getActionMethod())->toBe('handleModelBound')
        ->and($routes->getByName('test_endpoint.cancel')->getActionMethod())->toBe('handleMachineIdBound');
});

// ─── Handler Selection: Stateless ─────────────────────────────────────

test('stateless routes use handleStateless when no model provided', function (): void {
    MachineRouter::register(TestEndpointMachine::class, [
        'prefix' => '/api/stateless',
    ]);

    refreshRoutes();

    $routes = Route::getRoutes();
    $route  = $routes->getByName('test_endpoint.start');

    expect($route)->not->toBeNull()
        ->and($route->getActionMethod())->toBe('handleStateless');
});

test('stateless route URI uses endpoint uri directly without model param', function (): void {
    MachineRouter::register(TestEndpointMachine::class, [
        'prefix' => '/api/stateless-uri',
    ]);

    refreshRoutes();

    $routes = Route::getRoutes();
    $route  = $routes->getByName('test_endpoint.start');

    expect($route->uri())->toBe('api/stateless-uri/start');
});

// ─── Create Endpoint ──────────────────────────────────────────────────

test('create option generates POST /create route', function (): void {
    MachineRouter::register(TestEndpointMachine::class, [
        'prefix' => '/api/with-create',
        'create' => true,
    ]);

    refreshRoutes();

    $routes = Route::getRoutes();
    $route  = $routes->getByName('test_endpoint.create');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('api/with-create/create')
        ->and($route->getActionMethod())->toBe('handleCreate')
        ->and($route->methods())->toContain('POST');
});

test('create route stores machine class in defaults', function (): void {
    MachineRouter::register(TestEndpointMachine::class, [
        'prefix' => '/api/create-defaults',
        'create' => true,
    ]);

    refreshRoutes();

    $routes = Route::getRoutes();
    $route  = $routes->getByName('test_endpoint.create');

    expect($route->defaults)->toHaveKey('_machine_class')
        ->and($route->defaults['_machine_class'])->toBe(TestEndpointMachine::class);
});

test('no create route when create option is false', function (): void {
    MachineRouter::register(TestEndpointMachine::class, [
        'prefix' => '/api/no-create',
        'create' => false,
    ]);

    refreshRoutes();

    $routes = Route::getRoutes();

    expect($routes->getByName('test_endpoint.create'))->toBeNull();
});

test('no create route when create option is not set', function (): void {
    MachineRouter::register(TestEndpointMachine::class, [
        'prefix' => '/api/no-create-default',
    ]);

    refreshRoutes();

    $routes = Route::getRoutes();

    expect($routes->getByName('test_endpoint.create'))->toBeNull();
});

// ─── Middleware ────────────────────────────────────────────────────────

test('router-level middleware applied to all routes', function (): void {
    MachineRouter::register(TestEndpointMachine::class, [
        'prefix'     => '/api/mw-global',
        'middleware' => ['auth:api', 'throttle:60,1'],
    ]);

    refreshRoutes();

    $routes = Route::getRoutes();

    $startRoute    = $routes->getByName('test_endpoint.start');
    $completeRoute = $routes->getByName('test_endpoint.complete');
    $cancelRoute   = $routes->getByName('test_endpoint.cancel');

    // All routes should have the group-level middleware
    expect($startRoute->gatherMiddleware())->toContain('auth:api')
        ->and($startRoute->gatherMiddleware())->toContain('throttle:60,1')
        ->and($completeRoute->gatherMiddleware())->toContain('auth:api')
        ->and($cancelRoute->gatherMiddleware())->toContain('auth:api');
});

test('per-event middleware is additive to router middleware', function (): void {
    MachineRouter::register(TestMiddlewareEndpointMachine::class, [
        'prefix'     => '/api/mw-additive',
        'middleware' => ['auth:api'],
        'name'       => 'mw_test',
    ]);

    refreshRoutes();

    $routes = Route::getRoutes();
    $route  = $routes->getByName('mw_test.submit');

    $allMiddleware = $route->gatherMiddleware();

    expect($allMiddleware)->toContain('auth:api')
        ->and($allMiddleware)->toContain('verified')
        ->and($allMiddleware)->toContain('can:submit');
});

// ─── Route Defaults ───────────────────────────────────────────────────

test('routes store correct defaults for machine class and event type', function (): void {
    MachineRouter::register(TestEndpointMachine::class, [
        'prefix' => '/api/defaults',
    ]);

    refreshRoutes();

    $routes = Route::getRoutes();
    $route  = $routes->getByName('test_endpoint.start');

    expect($route->defaults['_machine_class'])->toBe(TestEndpointMachine::class)
        ->and($route->defaults['_event_type'])->toBe('START')
        ->and($route->defaults['_action_class'])->toBe(TestEndpointAction::class)
        ->and($route->defaults['_result_behavior'])->toBeNull()
        ->and($route->defaults['_status_code'])->toBe(200);
});

test('route defaults include result behavior and status code when set', function (): void {
    MachineRouter::register(TestEndpointMachine::class, [
        'prefix' => '/api/result-defaults',
    ]);

    refreshRoutes();

    $routes = Route::getRoutes();
    $route  = $routes->getByName('test_endpoint.complete');

    expect($route->defaults['_result_behavior'])->toBe('testEndpointResult')
        ->and($route->defaults['_status_code'])->toBe(201);
});

test('route defaults include model attribute when set', function (): void {
    MachineRouter::register(TestEndpointMachine::class, [
        'prefix'    => '/api/attr-defaults',
        'model'     => 'App\\Models\\Order',
        'attribute' => 'machine_ref',
    ]);

    refreshRoutes();

    $routes = Route::getRoutes();
    $route  = $routes->getByName('test_endpoint.start');

    expect($route->defaults['_model_attribute'])->toBe('machine_ref');
});

// ─── MachineIdFor Without Model ──────────────────────────────────────

test('machineIdFor works without model', function (): void {
    MachineRouter::register(TestEndpointMachine::class, [
        'prefix'       => '/api/standalone-machine-id',
        'machineIdFor' => ['START'],
    ]);

    refreshRoutes();

    $route = Route::getRoutes()->getByName('test_endpoint.start');

    expect($route->getActionMethod())->toBe('handleMachineIdBound')
        ->and($route->uri())->toBe('api/standalone-machine-id/{machineId}/start');
});

test('machineIdFor without model falls back to stateless for other events', function (): void {
    MachineRouter::register(TestEndpointMachine::class, [
        'prefix'       => '/api/mixed-standalone',
        'machineIdFor' => ['START'],
    ]);

    refreshRoutes();

    // START → machineIdBound
    $startRoute = Route::getRoutes()->getByName('test_endpoint.start');
    expect($startRoute->getActionMethod())->toBe('handleMachineIdBound');

    // COMPLETE → stateless (not in machineIdFor, no model)
    $completeRoute = Route::getRoutes()->getByName('test_endpoint.complete');
    expect($completeRoute->getActionMethod())->toBe('handleStateless')
        ->and($completeRoute->uri())->toBe('api/mixed-standalone/complete');
});

// ─── Empty Endpoints ──────────────────────────────────────────────────

test('register does nothing when machine has no endpoints', function (): void {
    $routeCountBefore = count(Route::getRoutes()->getRoutes());

    MachineRouter::register(TestNoEndpointMachine::class, [
        'prefix' => '/api/empty',
    ]);

    refreshRoutes();

    $routeCountAfter = count(Route::getRoutes()->getRoutes());

    expect($routeCountAfter)->toBe($routeCountBefore);
});
