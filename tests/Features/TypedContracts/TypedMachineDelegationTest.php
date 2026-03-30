<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Testing\InteractsWithMachines;
use Tarfinlabs\EventMachine\Tests\Stubs\Outputs\PaymentOutput;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\TypedChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\TypedParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\CatchAllParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\DiscriminatedChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\DiscriminatedParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\OptionalContractChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\TypedClosureInputParentMachine;

uses(InteractsWithMachines::class);

// ═══════════════════════════════════════════════════════════════
//  Typed Input
// ═══════════════════════════════════════════════════════════════

test('parent sends typed input via class reference to faked child', function (): void {
    TypedChildMachine::fake(output: ['paymentId' => 'pay_1', 'status' => 'done']);

    $tm = TypedParentMachine::test()
        ->send('START');

    $tm->assertState('completed');

    TypedChildMachine::assertInvoked();
});

test('typed input auto-resolves from parent context', function (): void {
    TypedChildMachine::fake(output: ['paymentId' => 'pay_2', 'status' => 'ok']);

    $tm = TypedParentMachine::test()
        ->send('START');

    // Child was invoked with resolved input from parent context
    $invocations = TypedChildMachine::getMachineInvocations();
    expect($invocations)->toHaveCount(1)
        ->and($invocations[0])->toHaveKey('orderId')
        ->and($invocations[0]['orderId'])->toBe('ORD-1')
        ->and($invocations[0]['amount'])->toBe(150);
});

// ═══════════════════════════════════���═════════════════════���═════
//  Typed Output — via Machine::fake
// ════════���══════════════════════��═══════════════════════════════

test('faked child with typed MachineOutput — parent receives it in @done', function (): void {
    TypedChildMachine::fake(
        output: new PaymentOutput(paymentId: 'pay_typed', status: 'success', transactionRef: 'ref_1'),
    );

    $tm = TypedParentMachine::test()
        ->send('START');

    $tm->assertState('completed');
});

test('faked child with array output — backward compat', function (): void {
    TypedChildMachine::fake(output: ['paymentId' => 'pay_array', 'status' => 'done']);

    $tm = TypedParentMachine::test()
        ->send('START');

    $tm->assertState('completed');
});

// ══════���════════════════════════════════��═══════════════════════
//  Typed Output — via simulateChildDone
// ══════════════��════════════════════════════════════════════════

test('simulateChildDone with typed MachineOutput', function (): void {
    $tm = TypedParentMachine::startingAt('delegating')
        ->simulateChildDone(
            childClass: TypedChildMachine::class,
            output: new PaymentOutput(paymentId: 'pay_sim', status: 'completed'),
            finalState: 'completed',
        );

    $tm->assertState('completed');
});

test('simulateChildDone with array output — backward compat', function (): void {
    $tm = TypedParentMachine::startingAt('delegating')
        ->simulateChildDone(
            childClass: TypedChildMachine::class,
            output: ['paymentId' => 'pay_arr', 'status' => 'ok'],
        );

    $tm->assertState('completed');
});

// ══��════════════════��═════════════════════════════��═════════════
//  Typed Failure — via simulateChildFail
// ════���═════════════════════���════════════════════════════════════

test('simulateChildFail routes to @fail', function (): void {
    $tm = TypedParentMachine::startingAt('delegating')
        ->simulateChildFail(
            childClass: TypedChildMachine::class,
            errorMessage: 'Gateway timeout',
        );

    $tm->assertState('errored');
});

// ═════════��════════════════════════���════════════════════════════
//  Discriminated Output — @done.{finalState}
// ══════════════════════��═══════════════════════════��════════════

test('@done.approved routes to completed', function (): void {
    DiscriminatedChildMachine::fake(finalState: 'approved');

    $tm = DiscriminatedParentMachine::test()
        ->send('START');

    $tm->assertState('completed');
});

test('@done.rejected routes to under_review', function (): void {
    DiscriminatedChildMachine::fake(finalState: 'rejected');

    $tm = DiscriminatedParentMachine::test()
        ->send('START');

    $tm->assertState('under_review');
});

// ═══════════════════════════════════════════════════════════════
//  Closure Input Adapter
// ═══════════════════════════════════════════════════════════════

test('parent sends typed input via closure adapter', function (): void {
    TypedChildMachine::fake(output: ['paymentId' => 'pay_closure', 'status' => 'ok']);

    $tm = TypedClosureInputParentMachine::test()
        ->send('START');

    $tm->assertState('completed');

    $invocations = TypedChildMachine::getMachineInvocations();
    expect($invocations)->toHaveCount(1)
        ->and($invocations[0]['orderId'])->toBe('ORD-CLOSURE')
        ->and($invocations[0]['amount'])->toBe(250);
});

// ═══════════════════════════════════════════════════════════════
//  Child Without Input Declaration
// ═══════════════════════════════════════════════════════════════

test('child without input declaration accepts any input', function (): void {
    // OptionalContractChildMachine has no 'input' config key — accepts whatever parent sends
    OptionalContractChildMachine::fake();

    // Inline parent that delegates with input to optional child
    $parent = TestMachine::define(config: [
        'id'      => 'optional_parent',
        'initial' => 'idle',
        'context' => ['orderId' => 'ORD-1'],
        'states'  => [
            'idle'       => ['on' => ['START' => 'delegating']],
            'delegating' => [
                'machine' => OptionalContractChildMachine::class,
                'input'   => ['orderId'],
                '@done'   => ['target' => 'completed'],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    $parent->send('START');

    // No exception — child has no input declaration, accepts anything
    OptionalContractChildMachine::assertInvoked();
    $parent->assertState('completed');
});

// ═══════════════════════════════════════════════════════════════
//  @done Catch-All
// ═══════════════════════════════════════════════════════════════

test('@done catch-all routes regardless of final state', function (): void {
    DiscriminatedChildMachine::fake(finalState: 'approved');

    $tm = CatchAllParentMachine::test()
        ->send('START');

    $tm->assertState('completed');
});

test('@done catch-all also works for rejected final state', function (): void {
    DiscriminatedChildMachine::fake(finalState: 'rejected');

    $tm = CatchAllParentMachine::test()
        ->send('START');

    $tm->assertState('completed');
});
