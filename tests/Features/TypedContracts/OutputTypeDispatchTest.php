<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\MachineOutput;
use Tarfinlabs\EventMachine\Behavior\OutputBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Outputs\GreenOutput;
use Tarfinlabs\EventMachine\Tests\Stubs\Outputs\PaymentOutput;

// ═══════════════════════════════════════════════════════════════
//  resolveChildOutput — MachineOutput dispatch
// ═══════════════════════════════════════════════════════════════

test('resolveChildOutput with MachineOutput subclass calls fromContext', function (): void {
    $definition = MachineDefinition::define(config: [
        'id'      => 'dispatch_test',
        'initial' => 'done',
        'context' => ['paymentId' => 'pay_1', 'status' => 'completed'],
        'states'  => [
            'done' => [
                'type'   => 'final',
                'output' => PaymentOutput::class,
            ],
        ],
    ]);

    $ctx = new ContextManager(['paymentId' => 'pay_1', 'status' => 'completed']);

    $result = MachineDefinition::resolveChildOutput(
        $definition->idMap['dispatch_test.done'],
        $ctx,
    );

    expect($result)->toBeInstanceOf(PaymentOutput::class)
        ->and($result->paymentId)->toBe('pay_1')
        ->and($result->status)->toBe('completed');
});

test('resolveChildOutput with array filters context keys', function (): void {
    $definition = MachineDefinition::define(config: [
        'id'      => 'array_test',
        'initial' => 'done',
        'context' => ['paymentId' => 'pay_2', 'status' => 'ok', 'secret' => 'hidden'],
        'states'  => [
            'done' => [
                'type'   => 'final',
                'output' => ['paymentId', 'status'],
            ],
        ],
    ]);

    $ctx = new ContextManager(['paymentId' => 'pay_2', 'status' => 'ok', 'secret' => 'hidden']);

    $result = MachineDefinition::resolveChildOutput(
        $definition->idMap['array_test.done'],
        $ctx,
    );

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('paymentId')
        ->and($result)->toHaveKey('status')
        ->and($result)->not->toHaveKey('secret');
});

test('resolveChildOutput with closure invokes closure', function (): void {
    $definition = MachineDefinition::define(config: [
        'id'      => 'closure_test',
        'initial' => 'done',
        'context' => ['paymentId' => 'pay_3'],
        'states'  => [
            'done' => [
                'type'   => 'final',
                'output' => fn (ContextManager $ctx) => ['custom' => $ctx->get('paymentId').'_closure'],
            ],
        ],
    ]);

    $ctx = new ContextManager(['paymentId' => 'pay_3']);

    $result = MachineDefinition::resolveChildOutput(
        $definition->idMap['closure_test.done'],
        $ctx,
    );

    expect($result)->toBeArray()
        ->and($result['custom'])->toBe('pay_3_closure');
});

test('resolveChildOutput with null output returns null', function (): void {
    $definition = MachineDefinition::define(config: [
        'id'      => 'null_test',
        'initial' => 'done',
        'context' => ['paymentId' => 'pay_4'],
        'states'  => [
            'done' => [
                'type' => 'final',
            ],
        ],
    ]);

    $ctx = new ContextManager(['paymentId' => 'pay_4']);

    $result = MachineDefinition::resolveChildOutput(
        $definition->idMap['null_test.done'],
        $ctx,
    );

    expect($result)->toBeNull();
});

test('MachineOutput is checked before OutputBehavior in resolveChildOutput', function (): void {
    // PaymentOutput extends MachineOutput — should call fromContext, not container resolve
    $definition = MachineDefinition::define(config: [
        'id'      => 'priority_test',
        'initial' => 'done',
        'context' => ['paymentId' => 'pay_5', 'status' => 'ok'],
        'states'  => [
            'done' => [
                'type'   => 'final',
                'output' => PaymentOutput::class,
            ],
        ],
    ]);

    $ctx = new ContextManager(['paymentId' => 'pay_5', 'status' => 'ok']);

    $result = MachineDefinition::resolveChildOutput(
        $definition->idMap['priority_test.done'],
        $ctx,
    );

    // If it went through OutputBehavior path, it would try __invoke() and fail
    expect($result)->toBeInstanceOf(PaymentOutput::class);
});

test('closure returning MachineOutput instance works', function (): void {
    $definition = MachineDefinition::define(config: [
        'id'      => 'closure_output_test',
        'initial' => 'done',
        'context' => ['paymentId' => 'pay_6', 'status' => 'ok'],
        'states'  => [
            'done' => [
                'type'   => 'final',
                'output' => fn (ContextManager $ctx) => new PaymentOutput(
                    paymentId: $ctx->get('paymentId'),
                    status: $ctx->get('status'),
                ),
            ],
        ],
    ]);

    $ctx = new ContextManager(['paymentId' => 'pay_6', 'status' => 'ok']);

    $result = MachineDefinition::resolveChildOutput(
        $definition->idMap['closure_output_test.done'],
        $ctx,
    );

    expect($result)->toBeInstanceOf(PaymentOutput::class)
        ->and($result->paymentId)->toBe('pay_6');
});

// ═══════════════════════════════════════════════════════════════
//  resolveChildOutput — OutputBehavior class
// ═══════════════════════════════════════════════════════════════

test('resolveChildOutput with OutputBehavior class resolves via container and invokes', function (): void {
    // GreenOutput extends OutputBehavior — resolved via container, __invoke called
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'ob_resolve_test',
            'initial' => 'done',
            'context' => ['paymentId' => 'pay_ob', 'status' => 'success'],
            'states'  => [
                'done' => [
                    'type'   => 'final',
                    'output' => GreenOutput::class,
                ],
            ],
        ],
    );

    $ctx = new ContextManager(['paymentId' => 'pay_ob', 'status' => 'success']);

    $result = MachineDefinition::resolveChildOutput(
        $definition->idMap['ob_resolve_test.done'],
        $ctx,
    );

    // GreenOutput returns Carbon::now() — should be a Carbon instance
    expect($result)->toBeInstanceOf(Carbon::class);
});

// ═══════════════════════════════════════════════════════════════
//  OutputBehavior returning MachineOutput — instance check
// ═══════════════════════════════════════════════════════════════

test('OutputBehavior returning MachineOutput — return value is MachineOutput instance', function (): void {
    // Use closure returning MachineOutput to verify type dispatch handles it
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'ob_mo_instance_test',
            'initial' => 'done',
            'context' => ['paymentId' => 'pay_inst', 'status' => 'ok'],
            'states'  => [
                'done' => [
                    'type'   => 'final',
                    'output' => fn (ContextManager $ctx) => new PaymentOutput(
                        paymentId: $ctx->get('paymentId'),
                        status: $ctx->get('status'),
                    ),
                ],
            ],
        ],
    );

    $ctx = new ContextManager(['paymentId' => 'pay_inst', 'status' => 'ok']);

    $result = MachineDefinition::resolveChildOutput(
        $definition->idMap['ob_mo_instance_test.done'],
        $ctx,
    );

    expect($result)->toBeInstanceOf(MachineOutput::class)
        ->and($result)->toBeInstanceOf(PaymentOutput::class);
});

// ═══════════════════════════════════════════════════════════════
//  Machine::output() — resolves MachineOutput on current state
// ═══════════════════════════════════════════════════════════════

test('Machine::output() returns MachineOutput when current state defines MachineOutput subclass', function (): void {
    // Create an inline machine with output: PaymentOutput::class on a final state
    $definition = MachineDefinition::define(config: [
        'id'      => 'output_method_test',
        'initial' => 'idle',
        'context' => ['paymentId' => 'pay_method', 'status' => 'ok'],
        'states'  => [
            'idle' => [
                'on' => ['DONE' => 'completed'],
            ],
            'completed' => [
                'type'   => 'final',
                'output' => PaymentOutput::class,
            ],
        ],
    ]);

    $machine = Machine::create(definition: $definition);
    $machine->send(['type' => 'DONE']);

    $output = $machine->output();

    expect($output)->toBeInstanceOf(PaymentOutput::class)
        ->and($output->paymentId)->toBe('pay_method')
        ->and($output->status)->toBe('ok');
});

// ═══════════════════════════════════════════════════════════════
//  Invalid output class — stdClass
// ═══════════════════════════════════════════════════════════════

test('output key with invalid class that is neither MachineOutput nor OutputBehavior throws', function (): void {
    $definition = MachineDefinition::define(config: [
        'id'      => 'invalid_output_test',
        'initial' => 'done',
        'context' => [],
        'states'  => [
            'done' => [
                'type'   => 'final',
                'output' => stdClass::class,
            ],
        ],
    ]);

    $ctx = new ContextManager([]);

    // stdClass has no __invoke, so resolving it should throw
    MachineDefinition::resolveChildOutput(
        $definition->idMap['invalid_output_test.done'],
        $ctx,
    );
})->throws(Error::class);
