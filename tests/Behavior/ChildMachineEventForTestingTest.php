<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Behavior\ChildMachineDoneEvent;
use Tarfinlabs\EventMachine\Behavior\ChildMachineFailEvent;

// region ChildMachineDoneEvent::forTesting()

it('creates a done event with output data', function (): void {
    $event = ChildMachineDoneEvent::forTesting(['output' => ['statusCode' => 3]]);

    expect($event->output('statusCode'))->toBe(3)
        ->and($event->childMachineId())->toBe('test')
        ->and($event->childMachineClass())->toBe('TestMachine');
});

it('creates a done event with output containing multiple keys', function (): void {
    $event = ChildMachineDoneEvent::forTesting([
        'output' => ['amount' => 1500, 'currency' => 'TRY'],
    ]);

    expect($event->output('amount'))->toBe(1500)
        ->and($event->output('currency'))->toBe('TRY');
});

it('creates a done event with final state', function (): void {
    $event = ChildMachineDoneEvent::forTesting([
        'output'      => ['status' => 'ok'],
        'final_state' => 'approved',
    ]);

    expect($event->finalState())->toBe('approved')
        ->and($event->output('status'))->toBe('ok');
});

it('creates a done event with custom machine identity', function (): void {
    $event = ChildMachineDoneEvent::forTesting([
        'machine_id'    => 'custom-id',
        'machine_class' => 'App\\Machines\\PaymentMachine',
    ]);

    expect($event->childMachineId())->toBe('custom-id')
        ->and($event->childMachineClass())->toBe('App\\Machines\\PaymentMachine');
});

it('creates a done event with zero-config defaults', function (): void {
    $event = ChildMachineDoneEvent::forTesting();

    expect($event->output())->toBe([])
        ->and($event->childMachineId())->toBe('test')
        ->and($event->childMachineClass())->toBe('TestMachine')
        ->and($event->finalState())->toBeNull();
});

it('creates a done event with correct type', function (): void {
    $event = ChildMachineDoneEvent::forTesting(['output' => ['x' => 1]]);

    expect($event->type)->toBe('CHILD_MACHINE_DONE');
});

// endregion

// region ChildMachineFailEvent::forTesting()

it('creates a fail event with only error message', function (): void {
    $event = ChildMachineFailEvent::forTesting(['error_message' => 'Gateway timeout']);

    expect($event->errorMessage())->toBe('Gateway timeout')
        ->and($event->output())->toBe([])
        ->and($event->childMachineId())->toBe('test')
        ->and($event->childMachineClass())->toBe('TestMachine');
});

it('creates a fail event with error message and output', function (): void {
    $event = ChildMachineFailEvent::forTesting([
        'error_message' => 'API error',
        'output'        => ['errorCode' => 'E311', 'retryable' => true],
    ]);

    expect($event->errorMessage())->toBe('API error')
        ->and($event->output('errorCode'))->toBe('E311')
        ->and($event->output('retryable'))->toBeTrue();
});

it('creates a fail event with custom machine identity', function (): void {
    $event = ChildMachineFailEvent::forTesting([
        'error_message' => 'timeout',
        'machine_id'    => 'child-abc',
        'machine_class' => 'App\\Machines\\KycMachine',
    ]);

    expect($event->childMachineId())->toBe('child-abc')
        ->and($event->childMachineClass())->toBe('App\\Machines\\KycMachine');
});

it('creates a fail event with zero-config defaults', function (): void {
    $event = ChildMachineFailEvent::forTesting();

    expect($event->errorMessage())->toBeNull()
        ->and($event->output())->toBe([])
        ->and($event->childMachineId())->toBe('test')
        ->and($event->childMachineClass())->toBe('TestMachine');
});

it('creates a fail event with correct type', function (): void {
    $event = ChildMachineFailEvent::forTesting(['error_message' => 'fail']);

    expect($event->type)->toBe('CHILD_MACHINE_FAIL');
});

// endregion
