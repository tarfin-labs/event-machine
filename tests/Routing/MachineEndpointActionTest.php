<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Routing\MachineEndpointAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestEndpointAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestEndpointMachine;

// === withMachineContext ===

test('withMachineContext sets machine and state and returns self', function (): void {
    $action = new TestEndpointAction();

    $machine = TestEndpointMachine::create();
    $state   = $machine->state;

    $result = $action->withMachineContext($machine, $state);

    expect($result)->toBe($action)
        ->and($result)->toBeInstanceOf(MachineEndpointAction::class);
});

// === Default hook methods ===

test('default before is a no-op and does not throw', function (): void {
    $action = new class() extends MachineEndpointAction {};

    // Should not throw - just a no-op
    $action->before();

    expect(true)->toBeTrue();
});

test('default after is a no-op and does not throw', function (): void {
    $action = new class() extends MachineEndpointAction {};

    // Should not throw - just a no-op
    $action->after();

    expect(true)->toBeTrue();
});

test('default onException returns null', function (): void {
    $action = new class() extends MachineEndpointAction {};

    $result = $action->onException(new RuntimeException('test error'));

    expect($result)->toBeNull();
});

// === TestEndpointAction subclass ===

test('TestEndpointAction before method sets beforeCalled flag', function (): void {
    TestEndpointAction::reset();

    $action = new TestEndpointAction();
    $action->before();

    expect(TestEndpointAction::$beforeCalled)->toBeTrue()
        ->and(TestEndpointAction::$afterCalled)->toBeFalse();
});

test('TestEndpointAction after method sets afterCalled flag', function (): void {
    TestEndpointAction::reset();

    $action = new TestEndpointAction();
    $action->after();

    expect(TestEndpointAction::$afterCalled)->toBeTrue()
        ->and(TestEndpointAction::$beforeCalled)->toBeFalse();
});

test('TestEndpointAction onException captures exception and returns null', function (): void {
    TestEndpointAction::reset();

    $action    = new TestEndpointAction();
    $exception = new RuntimeException('test exception');

    $result = $action->onException($exception);

    expect($result)->toBeNull()
        ->and(TestEndpointAction::$lastException)->toBe($exception)
        ->and(TestEndpointAction::$lastException->getMessage())->toBe('test exception');
});

test('TestEndpointAction reset clears all static flags', function (): void {
    // Set all flags
    TestEndpointAction::$beforeCalled  = true;
    TestEndpointAction::$afterCalled   = true;
    TestEndpointAction::$lastException = new RuntimeException('x');

    TestEndpointAction::reset();

    expect(TestEndpointAction::$beforeCalled)->toBeFalse()
        ->and(TestEndpointAction::$afterCalled)->toBeFalse()
        ->and(TestEndpointAction::$lastException)->toBeNull();
});

// === TestEndpointMachine endpoint parsing ===

test('TestEndpointMachine parses all three endpoints', function (): void {
    $definition = TestEndpointMachine::definition();

    expect($definition->parsedEndpoints)->toHaveCount(3)
        ->and($definition->parsedEndpoints)->toHaveKeys(['START', 'COMPLETE', 'CANCEL']);
});

test('START endpoint has action class set', function (): void {
    $definition = TestEndpointMachine::definition();
    $endpoint   = $definition->parsedEndpoints['START'];

    expect($endpoint->eventType)->toBe('START')
        ->and($endpoint->actionClass)->toBe(TestEndpointAction::class)
        ->and($endpoint->resultBehavior)->toBeNull()
        ->and($endpoint->uri)->toBe('/start')
        ->and($endpoint->method)->toBe('POST');
});

test('COMPLETE endpoint has result behavior and custom status', function (): void {
    $definition = TestEndpointMachine::definition();
    $endpoint   = $definition->parsedEndpoints['COMPLETE'];

    expect($endpoint->eventType)->toBe('COMPLETE')
        ->and($endpoint->resultBehavior)->toBe('testEndpointResult')
        ->and($endpoint->statusCode)->toBe(201)
        ->and($endpoint->actionClass)->toBeNull()
        ->and($endpoint->uri)->toBe('/complete');
});

test('CANCEL endpoint uses auto-generated defaults', function (): void {
    $definition = TestEndpointMachine::definition();
    $endpoint   = $definition->parsedEndpoints['CANCEL'];

    expect($endpoint->eventType)->toBe('CANCEL')
        ->and($endpoint->uri)->toBe('/cancel')
        ->and($endpoint->method)->toBe('POST')
        ->and($endpoint->actionClass)->toBeNull()
        ->and($endpoint->resultBehavior)->toBeNull()
        ->and($endpoint->statusCode)->toBeNull()
        ->and($endpoint->middleware)->toBe([]);
});
