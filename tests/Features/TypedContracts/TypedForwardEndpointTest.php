<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\MachineOutput;
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Outputs\PaymentOutput;
use Tarfinlabs\EventMachine\Routing\ForwardedEndpointDefinition;
use Tarfinlabs\EventMachine\Exceptions\MachineOutputInjectionException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\PaymentStepOutput;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\RenameForwardParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardChildEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardParentEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\FullConfigForwardParentMachine;

// ═══════════════════════════════════════════════════════════════
//  ForwardContext Removal
// ═══════════════════════════════════════════════════════════════

test('ForwardContext class no longer exists', function (): void {
    expect(class_exists('Tarfinlabs\EventMachine\Routing\ForwardContext'))->toBeFalse();
});

// ═══════════════════════════════════════════════════════════════
//  InvokableBehavior Parameter Injection
// ═══════════════════════════════════════════════════════════════

test('InvokableBehavior does not accept ForwardContext parameter', function (): void {
    $reflection = new ReflectionMethod(InvokableBehavior::class, 'injectInvokableBehaviorParameters');

    $paramNames = array_map(
        fn (ReflectionParameter $p) => $p->getName(),
        $reflection->getParameters(),
    );

    expect($paramNames)->not->toContain('forwardContext');
});

test('InvokableBehavior accepts childOutput parameter', function (): void {
    $reflection = new ReflectionMethod(InvokableBehavior::class, 'injectInvokableBehaviorParameters');

    $paramNames = array_map(
        fn (ReflectionParameter $p) => $p->getName(),
        $reflection->getParameters(),
    );

    expect($paramNames)->toContain('childOutput');
});

// ═══════════════════════════════════════════════════════════════
//  resolveChildOutput — MachineOutput dispatch
// ═══════════════════════════════════════════════════════════════

test('resolveChildOutput returns MachineOutput when state defines MachineOutput class', function (): void {
    $definition = MachineDefinition::define(config: [
        'id'      => 'fwd_output_test',
        'initial' => 'done',
        'context' => ['paymentId' => 'pay_fwd_1', 'status' => 'charged'],
        'states'  => [
            'done' => [
                'type'   => 'final',
                'output' => PaymentOutput::class,
            ],
        ],
    ]);

    $ctx = new ContextManager(['paymentId' => 'pay_fwd_1', 'status' => 'charged']);

    $result = MachineDefinition::resolveChildOutput(
        $definition->idMap['fwd_output_test.done'],
        $ctx,
    );

    expect($result)->toBeInstanceOf(PaymentOutput::class)
        ->and($result->paymentId)->toBe('pay_fwd_1')
        ->and($result->status)->toBe('charged');
});

test('resolveChildOutput returns array when state defines array filter', function (): void {
    $definition = MachineDefinition::define(config: [
        'id'      => 'fwd_array_test',
        'initial' => 'done',
        'context' => ['paymentId' => 'pay_fwd_2', 'status' => 'ok', 'secret' => 'hidden'],
        'states'  => [
            'done' => [
                'type'   => 'final',
                'output' => ['paymentId', 'status'],
            ],
        ],
    ]);

    $ctx = new ContextManager(['paymentId' => 'pay_fwd_2', 'status' => 'ok', 'secret' => 'hidden']);

    $result = MachineDefinition::resolveChildOutput(
        $definition->idMap['fwd_array_test.done'],
        $ctx,
    );

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('paymentId')
        ->and($result)->toHaveKey('status')
        ->and($result)->not->toHaveKey('secret');
});

test('resolveChildOutput returns null when state has no output', function (): void {
    $definition = MachineDefinition::define(config: [
        'id'      => 'fwd_null_test',
        'initial' => 'done',
        'context' => ['paymentId' => 'pay_fwd_3'],
        'states'  => [
            'done' => [
                'type' => 'final',
            ],
        ],
    ]);

    $ctx = new ContextManager(['paymentId' => 'pay_fwd_3']);

    $result = MachineDefinition::resolveChildOutput(
        $definition->idMap['fwd_null_test.done'],
        $ctx,
    );

    expect($result)->toBeNull();
});

test('resolveChildOutput returns closure result when state defines closure', function (): void {
    $definition = MachineDefinition::define(config: [
        'id'      => 'fwd_closure_test',
        'initial' => 'done',
        'context' => ['paymentId' => 'pay_fwd_4'],
        'states'  => [
            'done' => [
                'type'   => 'final',
                'output' => fn (ContextManager $ctx) => ['custom' => $ctx->get('paymentId').'_fwd'],
            ],
        ],
    ]);

    $ctx = new ContextManager(['paymentId' => 'pay_fwd_4']);

    $result = MachineDefinition::resolveChildOutput(
        $definition->idMap['fwd_closure_test.done'],
        $ctx,
    );

    expect($result)->toBeArray()
        ->and($result['custom'])->toBe('pay_fwd_4_fwd');
});

// ═══════════════════════════════════════════════════════════════
//  MachineOutputInjectionException
// ═══════════════════════════════════════════════════════════════

test('MachineOutputInjectionException has correct factory method signature', function (): void {
    $reflection = new ReflectionMethod(MachineOutputInjectionException::class, 'missingChildOutput');

    $paramNames = array_map(
        fn (ReflectionParameter $p) => $p->getName(),
        $reflection->getParameters(),
    );

    expect($paramNames)->toBe(['outputBehaviorClass', 'expectedOutputClass', 'childMachineClass', 'childStateName']);
});

test('MachineOutputInjectionException message includes expected class and child state', function (): void {
    $exception = MachineOutputInjectionException::missingChildOutput(
        outputBehaviorClass: 'App\\Outputs\\MyOutputBehavior',
        expectedOutputClass: PaymentOutput::class,
        childMachineClass: 'App\\Machines\\ChildMachine',
        childStateName: 'awaiting_input',
    );

    expect($exception->getMessage())
        ->toContain(PaymentOutput::class)
        ->toContain('awaiting_input')
        ->toContain('App\\Outputs\\MyOutputBehavior')
        ->toContain('App\\Machines\\ChildMachine');
});

// ═══════════════════════════════════════════════════════════════
//  Forward config formats
// ═══════════════════════════════════════════════════════════════

test('forward plain format produces ForwardedEndpointDefinition', function (): void {
    $definition = ForwardParentEndpointMachine::definition();

    expect($definition->forwardedEndpoints)->toHaveKey('PROVIDE_CARD');

    $fwd = $definition->forwardedEndpoints['PROVIDE_CARD'];

    expect($fwd)->toBeInstanceOf(ForwardedEndpointDefinition::class)
        ->and($fwd->parentEventType)->toBe('PROVIDE_CARD')
        ->and($fwd->childEventType)->toBe('PROVIDE_CARD')
        ->and($fwd->childMachineClass)->toBe(ForwardChildEndpointMachine::class);
});

test('forward rename format produces correct child event type', function (): void {
    $definition = RenameForwardParentMachine::definition();

    expect($definition->forwardedEndpoints)->toHaveKey('CANCEL_ORDER');

    $fwd = $definition->forwardedEndpoints['CANCEL_ORDER'];

    expect($fwd)->toBeInstanceOf(ForwardedEndpointDefinition::class)
        ->and($fwd->parentEventType)->toBe('CANCEL_ORDER')
        ->and($fwd->childEventType)->toBe('ABORT');
});

test('forward full config format with output class', function (): void {
    $definition = FullConfigForwardParentMachine::definition();

    expect($definition->forwardedEndpoints)->toHaveKey('PROVIDE_CARD');

    $fwd = $definition->forwardedEndpoints['PROVIDE_CARD'];

    expect($fwd)->toBeInstanceOf(ForwardedEndpointDefinition::class)
        ->and($fwd->output)->toBe(PaymentStepOutput::class)
        ->and($fwd->statusCode)->toBe(202)
        ->and($fwd->method)->toBe('PATCH');
});
