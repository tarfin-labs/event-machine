<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Testing\InteractsWithMachines;
use Tarfinlabs\EventMachine\Tests\Stubs\Outputs\PaymentOutput;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\TypedChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\TypedParentMachine;

uses(InteractsWithMachines::class);

// ═══════════════════════════════════════════════════════════════
//  Machine::fake — MachineOutput support
// ═══════════════════════════════════════════════════════════════

test('Machine::fake accepts MachineOutput instance', function (): void {
    TypedChildMachine::fake(
        output: new PaymentOutput(paymentId: 'pay_fake', status: 'success'),
    );

    $tm = TypedParentMachine::test()->send('START');

    TypedChildMachine::assertInvoked();
    $tm->assertState('completed');
});

test('Machine::fake accepts array output', function (): void {
    TypedChildMachine::fake(output: ['paymentId' => 'pay_arr', 'status' => 'done']);

    $tm = TypedParentMachine::test()->send('START');

    TypedChildMachine::assertInvoked();
    $tm->assertState('completed');
});

test('Machine::fake stores output_class for MachineOutput', function (): void {
    TypedChildMachine::fake(
        output: new PaymentOutput(paymentId: 'pay_cls', status: 'ok'),
    );

    $fake = TypedChildMachine::getMachineFake();

    expect($fake)->not->toBeNull()
        ->and($fake['output_class'])->toBe(PaymentOutput::class)
        ->and($fake['output'])->toBe([
            'paymentId'      => 'pay_cls',
            'status'         => 'ok',
            'transactionRef' => null,
        ]);
});

// ═══════════════════════════════════════════════════════════════
//  simulateChildDone — typed output
// ═══════════════════════════════════════════════════════════════

test('simulateChildDone accepts MachineOutput', function (): void {
    $tm = TypedParentMachine::startingAt('delegating')
        ->simulateChildDone(
            childClass: TypedChildMachine::class,
            output: new PaymentOutput(paymentId: 'pay_sim', status: 'completed'),
        );

    $tm->assertState('completed');
});

test('simulateChildDone accepts array', function (): void {
    $tm = TypedParentMachine::startingAt('delegating')
        ->simulateChildDone(
            childClass: TypedChildMachine::class,
            output: ['paymentId' => 'pay_arr', 'status' => 'ok'],
        );

    $tm->assertState('completed');
});

// ═══════════════════════════════════════════════════════════════
//  simulateChildFail — typed failure
// ═══════════════════════════════════════════════════════════════

test('simulateChildFail accepts MachineFailure', function (): void {
    $tm = TypedParentMachine::startingAt('delegating')
        ->simulateChildFail(
            childClass: TypedChildMachine::class,
            errorMessage: 'Gateway timeout',
        );

    $tm->assertState('errored');
});
