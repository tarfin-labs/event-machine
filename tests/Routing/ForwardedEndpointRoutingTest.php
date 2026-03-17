<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tarfinlabs\EventMachine\Routing\MachineRouter;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestNoEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\AbortEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ProvideCardEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ConfirmPaymentEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardEndpointAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\FqcnForwardParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardChildEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardParentEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\FullConfigForwardParentMachine;

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
|
| After registering routes via MachineRouter, the internal name-lookup
| table must be rebuilt so getByName() works.
*/

function refreshForwardedRoutes(): void
{
    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
}

// -- Route Registration ---------------------------------------------------

test('it auto-registers forwarded endpoints alongside explicit endpoints', function (): void {
    MachineRouter::register(ForwardParentEndpointMachine::class, [
        'prefix' => '/api/fwd-auto',
    ]);

    refreshForwardedRoutes();

    $routes = Route::getRoutes();

    // Explicit endpoints
    expect($routes->getByName('forward_endpoint_parent.start'))->not->toBeNull()
        ->and($routes->getByName('forward_endpoint_parent.cancel'))->not->toBeNull()
        // Forwarded endpoints
        ->and($routes->getByName('forward_endpoint_parent.provide_card'))->not->toBeNull()
        ->and($routes->getByName('forward_endpoint_parent.confirm_payment'))->not->toBeNull();
});

test('forwarded endpoints use same binding mode as parent (model-bound)', function (): void {
    MachineRouter::register(ForwardParentEndpointMachine::class, [
        'prefix'    => '/api/fwd-model',
        'model'     => 'App\\Models\\Order',
        'attribute' => 'machine',
        'modelFor'  => ['START', 'CANCEL'],
    ]);

    refreshForwardedRoutes();

    $routes = Route::getRoutes();
    $route  = $routes->getByName('forward_endpoint_parent.provide_card');

    expect($route)->not->toBeNull()
        ->and($route->getActionMethod())->toBe('handleForwardedModelBound');
});

test('forwarded endpoints use same binding mode as parent (machineId-bound)', function (): void {
    MachineRouter::register(ForwardParentEndpointMachine::class, [
        'prefix' => '/api/fwd-machineid',
    ]);

    refreshForwardedRoutes();

    $routes = Route::getRoutes();
    $route  = $routes->getByName('forward_endpoint_parent.provide_card');

    expect($route)->not->toBeNull()
        ->and($route->getActionMethod())->toBe('handleForwardedMachineIdBound');
});

test('forwarded route names follow pattern: {prefix}.{lowercase_event}', function (): void {
    MachineRouter::register(ForwardParentEndpointMachine::class, [
        'prefix' => '/api/fwd-naming',
    ]);

    refreshForwardedRoutes();

    $routes = Route::getRoutes();

    expect($routes->getByName('forward_endpoint_parent.provide_card'))->not->toBeNull()
        ->and($routes->getByName('forward_endpoint_parent.confirm_payment'))->not->toBeNull();
});

test('forwarded routes have correct defaults', function (): void {
    MachineRouter::register(ForwardParentEndpointMachine::class, [
        'prefix' => '/api/fwd-defaults',
    ]);

    refreshForwardedRoutes();

    $routes = Route::getRoutes();
    $route  = $routes->getByName('forward_endpoint_parent.provide_card');

    expect($route->defaults['_machine_class'])->toBe(ForwardParentEndpointMachine::class)
        ->and($route->defaults['_event_type'])->toBe('PROVIDE_CARD')
        ->and($route->defaults['_child_event_type'])->toBe('PROVIDE_CARD')
        ->and($route->defaults['_child_machine_class'])->toBe(ForwardChildEndpointMachine::class)
        ->and($route->defaults['_child_event_class'])->toBe(ProvideCardEvent::class);
});

test('forwarded endpoints do not register when parent has no forward config', function (): void {
    $routeCountBefore = count(Route::getRoutes()->getRoutes());

    MachineRouter::register(TestNoEndpointMachine::class, [
        'prefix' => '/api/fwd-none',
    ]);

    refreshForwardedRoutes();

    $routeCountAfter = count(Route::getRoutes()->getRoutes());

    expect($routeCountAfter)->toBe($routeCountBefore);
});

// -- Binding Mode Inheritance ---------------------------------------------

test('forwarded endpoints are model-bound when parent has model + attribute configured', function (): void {
    MachineRouter::register(ForwardParentEndpointMachine::class, [
        'prefix'    => '/api/fwd-model-inherit',
        'model'     => 'App\\Models\\Order',
        'attribute' => 'machine',
        'modelFor'  => ['START', 'CANCEL'],
    ]);

    refreshForwardedRoutes();

    $routes = Route::getRoutes();

    $provideCard    = $routes->getByName('forward_endpoint_parent.provide_card');
    $confirmPayment = $routes->getByName('forward_endpoint_parent.confirm_payment');

    expect($provideCard->getActionMethod())->toBe('handleForwardedModelBound')
        ->and($confirmPayment->getActionMethod())->toBe('handleForwardedModelBound');
});

test('forwarded endpoints are machineId-bound when parent has no model configured', function (): void {
    MachineRouter::register(ForwardParentEndpointMachine::class, [
        'prefix' => '/api/fwd-no-model',
    ]);

    refreshForwardedRoutes();

    $routes = Route::getRoutes();

    $provideCard    = $routes->getByName('forward_endpoint_parent.provide_card');
    $confirmPayment = $routes->getByName('forward_endpoint_parent.confirm_payment');

    expect($provideCard->getActionMethod())->toBe('handleForwardedMachineIdBound')
        ->and($confirmPayment->getActionMethod())->toBe('handleForwardedMachineIdBound');
});

test('model-bound forwarded endpoint uses parent model parameter name', function (): void {
    MachineRouter::register(ForwardParentEndpointMachine::class, [
        'prefix'    => '/api/fwd-model-param',
        'model'     => 'App\\Models\\Order',
        'attribute' => 'machine',
        'modelFor'  => ['START', 'CANCEL'],
    ]);

    refreshForwardedRoutes();

    $routes = Route::getRoutes();
    $route  = $routes->getByName('forward_endpoint_parent.provide_card');

    // Model param is camelCase of class basename: 'Order' -> 'order'
    expect($route->uri())->toContain('{order}');
});

test('machineId-bound forwarded endpoint uses {machineId} parameter', function (): void {
    MachineRouter::register(ForwardParentEndpointMachine::class, [
        'prefix' => '/api/fwd-machine-param',
    ]);

    refreshForwardedRoutes();

    $routes = Route::getRoutes();
    $route  = $routes->getByName('forward_endpoint_parent.provide_card');

    expect($route->uri())->toContain('{machineId}');
});

test('forwarded model-bound handler is handleForwardedModelBound', function (): void {
    MachineRouter::register(ForwardParentEndpointMachine::class, [
        'prefix'    => '/api/fwd-handler-model',
        'model'     => 'App\\Models\\Order',
        'attribute' => 'machine',
        'modelFor'  => ['START'],
    ]);

    refreshForwardedRoutes();

    $route = Route::getRoutes()->getByName('forward_endpoint_parent.provide_card');

    expect($route->getActionMethod())->toBe('handleForwardedModelBound');
});

test('forwarded machineId-bound handler is handleForwardedMachineIdBound', function (): void {
    MachineRouter::register(ForwardParentEndpointMachine::class, [
        'prefix' => '/api/fwd-handler-mid',
    ]);

    refreshForwardedRoutes();

    $route = Route::getRoutes()->getByName('forward_endpoint_parent.provide_card');

    expect($route->getActionMethod())->toBe('handleForwardedMachineIdBound');
});

// -- Endpoint Customization -----------------------------------------------

test('custom URI in forward config overrides auto-generated URI in route', function (): void {
    MachineRouter::register(FullConfigForwardParentMachine::class, [
        'prefix' => '/api/fwd-custom-uri',
    ]);

    refreshForwardedRoutes();

    $route = Route::getRoutes()->getByName('full_config_forward_parent.provide_card');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toContain('enter-payment-details');
});

test('custom method in forward config registers correct HTTP method', function (): void {
    MachineRouter::register(FullConfigForwardParentMachine::class, [
        'prefix' => '/api/fwd-custom-method',
    ]);

    refreshForwardedRoutes();

    $route = Route::getRoutes()->getByName('full_config_forward_parent.provide_card');

    expect($route)->not->toBeNull()
        ->and($route->methods())->toContain('PATCH');
});

test('action class is stored in route defaults for forwarded endpoint', function (): void {
    MachineRouter::register(FullConfigForwardParentMachine::class, [
        'prefix' => '/api/fwd-action-class',
    ]);

    refreshForwardedRoutes();

    $route = Route::getRoutes()->getByName('full_config_forward_parent.provide_card');

    expect($route->defaults['_action_class'])->toBe(ForwardEndpointAction::class);
});

test('FQCN forward keys produce correct route names', function (): void {
    MachineRouter::register(FqcnForwardParentMachine::class, [
        'prefix' => '/api/fwd-fqcn',
    ]);

    refreshForwardedRoutes();

    $routes = Route::getRoutes();

    // ProvideCardEvent::class (Format 1 FQCN) -> resolves to PROVIDE_CARD
    expect($routes->getByName('fqcn_forward_parent.provide_card'))->not->toBeNull()
        // ConfirmPaymentEvent::class => AbortEvent::class (Format 2 FQCN rename)
        // Parent event: CONFIRM_PAYMENT, child event: ABORT
        ->and($routes->getByName('fqcn_forward_parent.confirm_payment'))->not->toBeNull();
});

// -- Middleware ------------------------------------------------------------

test('forwarded endpoint middleware is additive with router middleware', function (): void {
    MachineRouter::register(FullConfigForwardParentMachine::class, [
        'prefix'     => '/api/fwd-mw',
        'middleware' => ['auth:api'],
    ]);

    refreshForwardedRoutes();

    $route = Route::getRoutes()->getByName('full_config_forward_parent.provide_card');

    $allMiddleware = $route->gatherMiddleware();

    // Router-level middleware
    expect($allMiddleware)->toContain('auth:api')
        // Per-endpoint middleware from forward config
        ->and($allMiddleware)->toContain('throttle:10');
});
