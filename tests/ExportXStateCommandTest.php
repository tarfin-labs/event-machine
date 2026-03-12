<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Commands\ExportXStateCommand;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\SimpleEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AbcMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\ValidatedEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\OrderMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\GuardedMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ElevatorMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\MachineRegisteredEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\EventResolution\EventResolutionMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Compound\ConditionalCompoundOnDoneMachine;

// region Basic Export

it('exports a simple machine to XState JSON format', function (): void {
    $this->artisan('machine:xstate', [
        'machine'  => AbcMachine::class,
        '--stdout' => true,
    ])->assertSuccessful();
});

it('exports GuardedMachine with guards and actions', function (): void {
    $result = $this->artisan('machine:xstate', [
        'machine'  => GuardedMachine::class,
        '--stdout' => true,
    ]);

    $result->assertSuccessful();
    $output = $result->expectsOutputToContain('isEvenGuard');
});

it('exports OrderMachine with calculators', function (): void {
    $result = $this->artisan('machine:xstate', [
        'machine'  => OrderMachine::class,
        '--stdout' => true,
    ]);

    $result->assertSuccessful();
});

it('exports ElevatorMachine with always transitions', function (): void {
    $result = $this->artisan('machine:xstate', [
        'machine'  => ElevatorMachine::class,
        '--stdout' => true,
    ]);

    $result->assertSuccessful();
});

it('exports compound machine with onDone transitions', function (): void {
    $result = $this->artisan('machine:xstate', [
        'machine'  => ConditionalCompoundOnDoneMachine::class,
        '--stdout' => true,
    ]);

    $result->assertSuccessful();
});

// endregion

// region JSON Structure Validation

it('produces valid XState JSON structure for a guarded machine', function (): void {
    $machine = GuardedMachine::create();

    // Use the command's internal logic via reflection to get the JSON
    $command = new ExportXStateCommand();
    $method  = new ReflectionMethod($command, 'buildMachineNode');
    $xstate  = $method->invoke($command, $machine->definition);

    // Root structure
    expect($xstate)
        ->toHaveKey('id')
        ->toHaveKey('initial')
        ->toHaveKey('context')
        ->toHaveKey('states');

    // Initial state
    expect($xstate['initial'])->toBe('active');

    // Context
    expect($xstate['context'])->toBe(['count' => 1]);

    // States
    expect($xstate['states'])->toHaveKeys(['active', 'processed']);

    // Guarded transition on CHECK event
    $checkTransition = $xstate['states']['active']['on']['CHECK'];
    expect($checkTransition)->toBeArray();
    expect($checkTransition)->toHaveCount(2);

    // First branch has guard
    expect($checkTransition[0])->toHaveKey('guard', 'isEvenGuard');
    expect($checkTransition[0])->toHaveKey('actions');

    // Second branch is the default (no guard, just target)
    expect($checkTransition[1])->toHaveKey('target', 'processed');
});

it('produces valid XState JSON structure for an order machine with calculators', function (): void {
    $machine = OrderMachine::create();

    $command = new ExportXStateCommand();
    $method  = new ReflectionMethod($command, 'buildMachineNode');
    $xstate  = $method->invoke($command, $machine->definition);

    // CREATE_ORDER transition
    $transition = $xstate['states']['idle']['on']['CREATE_ORDER'];
    expect($transition)->toBeArray();
    expect($transition)->toHaveKey('target', 'processing');
    expect($transition)->toHaveKey('guard', 'validateOrderGuard');
    expect($transition)->toHaveKey('actions');
    expect($transition['actions'])->toContain('createOrderAction');

    // Calculators in meta
    expect($transition)->toHaveKey('meta');
    expect($transition['meta']['eventMachine']['calculators'])
        ->toContain('calculateOrderTotalCalculator');
});

it('produces always transitions for elevator machine', function (): void {
    $machine = ElevatorMachine::create();

    $command = new ExportXStateCommand();
    $method  = new ReflectionMethod($command, 'buildMachineNode');
    $xstate  = $method->invoke($command, $machine->definition);

    // state_b should have "always" property (mapped from @always)
    expect($xstate['states']['state_b'])->toHaveKey('always');
    expect($xstate['states']['state_b']['always'])->toBe('state_c');
});

it('produces onDone transitions for compound machine', function (): void {
    $machine = ConditionalCompoundOnDoneMachine::create();

    $command = new ExportXStateCommand();
    $method  = new ReflectionMethod($command, 'buildMachineNode');
    $xstate  = $method->invoke($command, $machine->definition);

    // verification state should have onDone
    expect($xstate['states']['verification'])->toHaveKey('onDone');

    $onDone = $xstate['states']['verification']['onDone'];
    expect($onDone)->toBeArray();
    expect($onDone)->toHaveCount(2);

    // First branch: guarded → approved
    expect($onDone[0])->toHaveKey('target', 'approved');
    expect($onDone[0])->toHaveKey('guard');

    // Second branch: default → manual_review
    expect($onDone[1])->toHaveKey('target', 'manual_review');

    // Final states
    expect($xstate['states']['approved'])->toHaveKey('type', 'final');
    expect($xstate['states']['manual_review'])->toHaveKey('type', 'final');
});

it('produces valid XState JSON for TrafficLights with class-based behaviors', function (): void {
    $machine = TrafficLightsMachine::create();

    $command = new ExportXStateCommand();
    $method  = new ReflectionMethod($command, 'buildMachineNode');
    $xstate  = $method->invoke($command, $machine->definition);

    // Should have context
    expect($xstate)->toHaveKey('context');

    // Active state should have MULTIPLY event with guard
    $multiplyTransition = $xstate['states']['active']['on']['MULTIPLY'];
    expect($multiplyTransition)->toHaveKey('guard');
    expect($multiplyTransition)->toHaveKey('actions');

    // INCREASE event should resolve the class-based event type
    expect($xstate['states']['active']['on'])->toHaveKey('INCREASE');

    // Behavior catalog should be in meta
    expect($xstate)->toHaveKey('meta');
    expect($xstate['meta'])->toHaveKey('eventMachine');
});

// endregion

// region File Path Resolution

it('resolves machine from absolute file path', function (): void {
    $filePath = realpath(__DIR__.'/Stubs/Machines/GuardedMachine.php');

    $this->artisan('machine:xstate', [
        'machine'  => $filePath,
        '--stdout' => true,
    ])->assertSuccessful();
});

it('resolves machine from relative file path', function (): void {
    $this->artisan('machine:xstate', [
        'machine'  => 'tests/Stubs/Machines/GuardedMachine.php',
        '--stdout' => true,
    ])->assertSuccessful();
});

// endregion

// region Error Handling

it('fails gracefully for non-existent machine class', function (): void {
    $this->artisan('machine:xstate', [
        'machine'  => 'NonExistent\Machine',
        '--stdout' => true,
    ])->assertFailed();
});

it('fails gracefully for non-existent file path', function (): void {
    $this->artisan('machine:xstate', [
        'machine'  => '/non/existent/Machine.php',
        '--stdout' => true,
    ])->assertFailed();
});

// endregion

// region Event Payload Schema

it('extracts event payload schema from EventBehavior with rules', function (): void {
    $command = new ExportXStateCommand();
    $method  = new ReflectionMethod($command, 'extractEventPayloadSchema');

    // MachineRegisteredEvent has rules(): payload.amount => required, integer, min:1
    $schema = $method->invoke(
        $command,
        MachineRegisteredEvent::class
    );

    expect($schema)->toHaveKey('amount');
    expect($schema['amount']['type'])->toBe('number');
    expect($schema['amount']['required'])->toBeTrue();
});

it('extracts event payload schema from EventBehavior with ValidationContext parameter', function (): void {
    $command = new ExportXStateCommand();
    $method  = new ReflectionMethod($command, 'extractEventPayloadSchema');

    // ValidatedEvent has rules(ValidationContext): payload.attribute, payload.value
    $schema = $method->invoke(
        $command,
        ValidatedEvent::class
    );

    expect($schema)->toHaveKey('attribute');
    expect($schema['attribute']['type'])->toBe('number');
    expect($schema['attribute']['required'])->toBeTrue();

    expect($schema)->toHaveKey('value');
    expect($schema['value']['type'])->toBe('number');
    expect($schema['value']['required'])->toBeFalse();
});

it('returns empty schema for EventBehavior without rules', function (): void {
    $command = new ExportXStateCommand();
    $method  = new ReflectionMethod($command, 'extractEventPayloadSchema');

    // SimpleEvent has no rules() method
    $schema = $method->invoke(
        $command,
        SimpleEvent::class
    );

    expect($schema)->toBeEmpty();
});

it('infers TypeScript types from Laravel validation rules', function (): void {
    $command = new ExportXStateCommand();
    $method  = new ReflectionMethod($command, 'inferTypeFromRules');

    expect($method->invoke($command, ['required', 'integer']))->toBe('number');
    expect($method->invoke($command, ['required', 'numeric']))->toBe('number');
    expect($method->invoke($command, ['required', 'string']))->toBe('string');
    expect($method->invoke($command, ['required', 'email']))->toBe('string');
    expect($method->invoke($command, ['required', 'boolean']))->toBe('boolean');
    expect($method->invoke($command, ['required', 'array']))->toBe('array');
    expect($method->invoke($command, ['required']))->toBe('unknown');
});

it('includes event payload schema in behavior catalog', function (): void {
    $machine = EventResolutionMachine::create();

    $command = new ExportXStateCommand();
    $method  = new ReflectionMethod($command, 'buildMachineNode');
    $xstate  = $method->invoke($command, $machine->definition);

    // Behavior catalog should contain events with payload
    expect($xstate)->toHaveKey('meta');
    expect($xstate['meta']['eventMachine'])->toHaveKey('events');

    $eventCatalog = $xstate['meta']['eventMachine']['events'];
    expect($eventCatalog)->toHaveKey('TEST_EVENT');
    expect($eventCatalog['TEST_EVENT'])->toHaveKey('payload');

    $payload = $eventCatalog['TEST_EVENT']['payload'];
    expect($payload)->toHaveKey('amount');
    expect($payload['amount']['type'])->toBe('number');
    expect($payload['amount']['required'])->toBeTrue();
});

it('builds TypeScript event types block for JS format', function (): void {
    $machine = EventResolutionMachine::create();

    $command = new ExportXStateCommand();
    $method  = new ReflectionMethod($command, 'buildEventTypesBlock');
    $result  = $method->invoke($command, $machine->definition);

    expect($result)->toContain('type: "TEST_EVENT"');
    expect($result)->toContain('amount: number');
    expect($result)->toStartWith('{} as |');
});

it('returns empty event types block when no event behaviors exist', function (): void {
    $machine = AbcMachine::create();

    $command = new ExportXStateCommand();
    $method  = new ReflectionMethod($command, 'buildEventTypesBlock');
    $result  = $method->invoke($command, $machine->definition);

    expect($result)->toBe('');
});

// endregion

// region Behavior Name Resolution

it('resolves class-based behavior names to short types', function (): void {
    $command = new ExportXStateCommand();
    $method  = new ReflectionMethod($command, 'resolveBehaviorName');

    // String behavior stays as-is
    expect($method->invoke($command, 'isEvenGuard'))->toBe('isEvenGuard');

    // Non-string returns placeholder
    expect($method->invoke($command, fn () => true))->toBe('inlineBehavior');

    // Parameterized behavior strips params
    expect($method->invoke($command, 'guardName:param1,param2'))->toBe('guardName');
});

// endregion
