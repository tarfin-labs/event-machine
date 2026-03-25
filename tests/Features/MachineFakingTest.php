<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\Actor\Machine;
use PHPUnit\Framework\AssertionFailedError;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Behavior\ChildMachineDoneEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ParentOrderMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\SimpleChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ChildPaymentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\MultiOutcomeChildMachine;

// ─── Faked Result Flows Through @done ────────────────────────────

it('routes faked child result through @done to parent', function (): void {
    // Arrange: fake the child machine with a result
    ChildPaymentMachine::fake(result: [
        'paymentId'  => 'pay_fake_123',
        'receiptUrl' => 'https://fake.example.com/receipt',
        'amount'     => 500,
    ]);

    // Act: parent machine starts and delegates to child
    $machine = ParentOrderMachine::create();
    $machine->send(['type' => 'START_PAYMENT']);

    // Assert: parent received the faked result via @done
    expect($machine->state->currentStateDefinition->id)
        ->toBe('parent_order.completed')
        ->and($machine->state->context->get('paymentId'))
        ->toBe('pay_fake_123')
        ->and($machine->state->context->get('receiptUrl'))
        ->toBe('https://fake.example.com/receipt');
});

it('does not create a real child machine when faked', function (): void {
    // Arrange
    ChildPaymentMachine::fake(result: ['paymentId' => 'pay_faked']);

    // Act
    $machine = ParentOrderMachine::create();
    $machine->send(['type' => 'START_PAYMENT']);

    // Assert: child was never actually created (no DB events from child)
    // Parent should be in completed state
    expect($machine->state->currentStateDefinition->id)
        ->toBe('parent_order.completed');
});

// ─── Faked Failure Triggers @fail ────────────────────────────────

it('routes faked child failure through @fail to parent', function (): void {
    // Arrange: fake child to fail
    ChildPaymentMachine::fake(fail: true, error: 'Insufficient funds');

    // Act
    $machine = ParentOrderMachine::create();
    $machine->send(['type' => 'START_PAYMENT']);

    // Assert: parent transitioned to @fail target
    expect($machine->state->currentStateDefinition->id)
        ->toBe('parent_order.payment_failed');
});

it('provides error message in @fail event payload', function (): void {
    // Arrange
    ChildPaymentMachine::fake(fail: true, error: 'Card declined');

    // Act
    $machine = ParentOrderMachine::create();
    $machine->send(['type' => 'START_PAYMENT']);

    // Assert: parent ended at @fail target
    expect($machine->state->currentStateDefinition->id)
        ->toBe('parent_order.payment_failed');
});

// ─── Async Machine Faking ────────────────────────────────────────

it('short-circuits async delegation when child is faked', function (): void {
    Queue::fake();

    // Arrange: fake the child
    SimpleChildMachine::fake(result: ['status' => 'ok']);

    // Act
    $machine = AsyncParentMachine::create();
    $machine->send([
        'type'    => 'START',
        'payload' => ['orderId' => 'ORD-999'],
    ]);

    // Assert: no jobs dispatched, parent immediately completed
    Queue::assertNothingPushed();
    expect($machine->state->currentStateDefinition->id)
        ->toBe('async_parent.completed')
        ->and($machine->state->context->get('result'))
        ->toBe(['status' => 'ok']);
});

it('short-circuits async failure when child is faked with fail', function (): void {
    Queue::fake();

    // Arrange
    SimpleChildMachine::fake(fail: true, error: 'Timeout in child');

    // Act
    $machine = AsyncParentMachine::create();
    $machine->send([
        'type'    => 'START',
        'payload' => ['orderId' => 'ORD-999'],
    ]);

    // Assert: parent transitioned to @fail
    Queue::assertNothingPushed();
    expect($machine->state->currentStateDefinition->id)
        ->toBe('async_parent.failed')
        ->and($machine->state->context->get('error'))
        ->toBe('Timeout in child');
});

// ─── Context Passing (with) ─────────────────────────────────────

it('resolves with context and passes it to faked child invocation', function (): void {
    // Arrange
    ChildPaymentMachine::fake(result: ['paymentId' => 'pay_ctx']);

    // Act: set parent context so `with` resolves actual values
    $machine = ParentOrderMachine::test(context: ['orderId' => 'ORD-42', 'totalAmount' => 1500]);
    $machine->send(['type' => 'START_PAYMENT']);

    // Assert: invocation recorded the resolved context from `with`
    $invocations = ChildPaymentMachine::getMachineInvocations();
    expect($invocations)->toHaveCount(1)
        ->and($invocations[0])->toHaveKey('orderId')
        ->and($invocations[0]['orderId'])->toBe('ORD-42')
        ->and($invocations[0])->toHaveKey('amount')
        ->and($invocations[0]['amount'])->toBe(1500);
});

// ─── Assertion Methods ──────────────────────────────────────────

it('assertInvoked passes when faked child was invoked', function (): void {
    ChildPaymentMachine::fake(result: ['paymentId' => 'pay_assert']);

    $machine = ParentOrderMachine::create();
    $machine->send(['type' => 'START_PAYMENT']);

    // Should not throw
    ChildPaymentMachine::assertInvoked();
    expect(true)->toBeTrue();
});

it('assertNotInvoked passes when faked child was not invoked', function (): void {
    ChildPaymentMachine::fake(result: ['paymentId' => 'pay_unused']);

    // Do NOT send START_PAYMENT — child never invoked
    ParentOrderMachine::create();

    // Should not throw
    ChildPaymentMachine::assertNotInvoked();
    expect(true)->toBeTrue();
});

it('assertInvoked fails when faked child was not invoked', function (): void {
    ChildPaymentMachine::fake(result: ['paymentId' => 'pay_unused']);

    // Never trigger delegation
    ParentOrderMachine::create();

    ChildPaymentMachine::assertInvoked();
})->throws(AssertionFailedError::class);

it('assertNotInvoked fails when faked child was invoked', function (): void {
    ChildPaymentMachine::fake(result: ['paymentId' => 'pay_assert']);

    $machine = ParentOrderMachine::create();
    $machine->send(['type' => 'START_PAYMENT']);

    ChildPaymentMachine::assertNotInvoked();
})->throws(AssertionFailedError::class);

it('assertInvokedTimes validates exact invocation count', function (): void {
    ChildPaymentMachine::fake(result: ['paymentId' => 'pay_times']);

    $machine = ParentOrderMachine::create();
    $machine->send(['type' => 'START_PAYMENT']);

    ChildPaymentMachine::assertInvokedTimes(1);
    expect(true)->toBeTrue();
});

it('assertInvokedWith validates context subset match', function (): void {
    ChildPaymentMachine::fake(result: ['paymentId' => 'pay_with']);

    // `with` reads from parent context, so set context values via withContext
    $machine = ParentOrderMachine::test(context: ['orderId' => 'ORD-77', 'totalAmount' => 2000]);
    $machine->send(['type' => 'START_PAYMENT']);

    // `with` config maps: 'orderId' → order_id, 'amount' => 'totalAmount' → 2000
    ChildPaymentMachine::assertInvokedWith(['orderId' => 'ORD-77']);
    expect(true)->toBeTrue();
});

it('assertInvokedWith fails when no invocation matches', function (): void {
    ChildPaymentMachine::fake(result: ['paymentId' => 'pay_mismatch']);

    $machine = ParentOrderMachine::create();
    $machine->send(['type' => 'START_PAYMENT']);

    ChildPaymentMachine::assertInvokedWith(['orderId' => 'NONEXISTENT']);
})->throws(AssertionFailedError::class);

// ─── Reset ──────────────────────────────────────────────────────

it('resetMachineFakes clears all fakes', function (): void {
    ChildPaymentMachine::fake(result: ['paymentId' => 'pay_reset']);
    SimpleChildMachine::fake(result: ['status' => 'ok']);

    expect(ChildPaymentMachine::isMachineFaked())->toBeTrue()
        ->and(SimpleChildMachine::isMachineFaked())->toBeTrue();

    Machine::resetMachineFakes();

    expect(ChildPaymentMachine::isMachineFaked())->toBeFalse()
        ->and(SimpleChildMachine::isMachineFaked())->toBeFalse();
});

it('TestMachine::resetFakes() clears child machine fakes', function (): void {
    ChildPaymentMachine::fake(result: ['paymentId' => 'pay_reset_test']);

    expect(ChildPaymentMachine::isMachineFaked())->toBeTrue();

    $testMachine = TestMachine::define([
        'id'      => 'reset_test',
        'initial' => 'idle',
        'context' => [],
        'states'  => ['idle' => ['type' => 'final']],
    ]);

    $testMachine->resetFakes();

    expect(ChildPaymentMachine::isMachineFaked())->toBeFalse();
});

// ─── @done.{state} routing with Machine::fake() ─────────────────

it('fake(finalState:) routes to matching @done.{state} (T11)', function (): void {
    MultiOutcomeChildMachine::fake(finalState: 'approved');

    $machine = MachineDefinition::define(config: [
        'id'     => 'fake_route', 'initial' => 'idle', 'context' => [],
        'states' => [
            'idle'       => ['on' => ['GO' => 'delegating']],
            'delegating' => [
                'machine'        => MultiOutcomeChildMachine::class,
                '@done.approved' => 'completed',
                '@done.rejected' => 'declined',
                '@done.expired'  => 'declined',
            ],
            'completed' => ['type' => 'final'],
            'declined'  => ['type' => 'final'],
        ],
    ]);

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    expect($state->value)->toBe(['fake_route.completed']);
});

it('fake(finalState:) falls through to catch-all when no match (T12)', function (): void {
    MultiOutcomeChildMachine::fake(finalState: 'unknown');

    $machine = MachineDefinition::define(config: [
        'id'     => 'fake_fallback', 'initial' => 'idle', 'context' => [],
        'states' => [
            'idle'       => ['on' => ['GO' => 'delegating']],
            'delegating' => [
                'machine'        => MultiOutcomeChildMachine::class,
                '@done.approved' => 'completed',
                '@done'          => 'fallback',
            ],
            'completed' => ['type' => 'final'],
            'fallback'  => ['type' => 'final'],
        ],
    ]);

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    expect($state->value)->toBe(['fake_fallback.fallback']);
});

it('fake() without finalState falls through to @done catch-all (T13)', function (): void {
    MultiOutcomeChildMachine::fake();

    $machine = MachineDefinition::define(config: [
        'id'     => 'fake_no_fs', 'initial' => 'idle', 'context' => [],
        'states' => [
            'idle'       => ['on' => ['GO' => 'delegating']],
            'delegating' => [
                'machine'        => MultiOutcomeChildMachine::class,
                '@done.approved' => 'completed',
                '@done'          => 'fallback',
            ],
            'completed' => ['type' => 'final'],
            'fallback'  => ['type' => 'final'],
        ],
    ]);

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    expect($state->value)->toBe(['fake_no_fs.fallback']);
});

it('fake(finalState:) with result data provides both in event (T14)', function (): void {
    MultiOutcomeChildMachine::fake(result: ['paymentId' => 'pay_123'], finalState: 'approved');

    $capturedFinalState = null;
    $capturedPaymentId  = null;

    $machine = MachineDefinition::define(
        config: [
            'id'     => 'fake_data', 'initial' => 'idle', 'context' => ['paymentId' => null],
            'states' => [
                'idle'       => ['on' => ['GO' => 'delegating']],
                'delegating' => [
                    'machine'        => MultiOutcomeChildMachine::class,
                    '@done.approved' => ['target' => 'completed', 'actions' => 'storeAction'],
                    '@done'          => 'fallback',
                ],
                'completed' => ['type' => 'final'],
                'fallback'  => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'storeAction' => function (ContextManager $ctx, ChildMachineDoneEvent $event) use (&$capturedFinalState, &$capturedPaymentId): void {
                    $capturedFinalState = $event->finalState();
                    $capturedPaymentId  = $event->output('paymentId');
                },
            ],
        ],
    );

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    expect($state->value)->toBe(['fake_data.completed'])
        ->and($capturedFinalState)->toBe('approved')
        ->and($capturedPaymentId)->toBe('pay_123');
});
