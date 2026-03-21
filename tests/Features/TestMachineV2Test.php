<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Actor\Machine;
use PHPUnit\Framework\AssertionFailedError;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Testing\CommunicationRecorder;
use Tarfinlabs\EventMachine\Behavior\ChildMachineDoneEvent;
use Tarfinlabs\EventMachine\Behavior\ChildMachineFailEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\RaiseRetryAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\SendToTargetAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\DispatchToTargetAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ParentOrderMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\SimpleChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ChildPaymentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\FailingChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\MultiOutcomeChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateApprovedChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateRejectedChildMachine;

// ═══════════════════════════════════════════════════════════════
//  Category 1: Child Delegation (11 tests)
// ═══════════════════════════════════════════════════════════════

it('V1: fakingChild registers fake and returns self', function (): void {
    $testMachine = TestMachine::define(
        config: [
            'id'      => 'v1_parent',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'delegating'],
                ],
                'delegating' => [
                    'machine' => ImmediateApprovedChildMachine::class,
                    '@done'   => 'completed',
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
    );

    $result = $testMachine->fakingChild(
        childClass: ImmediateApprovedChildMachine::class,
        result: ['decision' => 'yes'],
    );

    expect($result)->toBe($testMachine);
    expect(ImmediateApprovedChildMachine::isMachineFaked())->toBeTrue();
});

it('V2: fakingChild is cleaned up by resetFakes', function (): void {
    $testMachine = TestMachine::define(
        config: [
            'id'      => 'v2_parent',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'delegating'],
                ],
                'delegating' => [
                    'machine' => ImmediateApprovedChildMachine::class,
                    '@done'   => 'completed',
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
    );

    $testMachine->fakingChild(ImmediateApprovedChildMachine::class, result: ['decision' => 'yes']);
    expect(ImmediateApprovedChildMachine::isMachineFaked())->toBeTrue();

    $testMachine->resetFakes();
    expect(ImmediateApprovedChildMachine::isMachineFaked())->toBeFalse();
});

it('V3: fakingChild with multiple children cleans all', function (): void {
    $testMachine = TestMachine::define(
        config: [
            'id'      => 'v3_parent',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'delegating'],
                ],
                'delegating' => [
                    'machine' => ImmediateApprovedChildMachine::class,
                    '@done'   => 'completed',
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
    );

    $testMachine
        ->fakingChild(ImmediateApprovedChildMachine::class, result: ['decision' => 'yes'])
        ->fakingChild(ImmediateRejectedChildMachine::class, result: ['reason' => 'no']);

    expect(ImmediateApprovedChildMachine::isMachineFaked())->toBeTrue();
    expect(ImmediateRejectedChildMachine::isMachineFaked())->toBeTrue();

    $testMachine->resetFakes();

    expect(ImmediateApprovedChildMachine::isMachineFaked())->toBeFalse();
    expect(ImmediateRejectedChildMachine::isMachineFaked())->toBeFalse();
});

it('V4: assertChildInvoked passes when child invoked', function (): void {
    ChildPaymentMachine::fake(result: ['payment_id' => 'pay_v4']);

    $testMachine = TestMachine::create(ParentOrderMachine::class);
    $testMachine->send('START_PAYMENT');
    $testMachine->assertChildInvoked(ChildPaymentMachine::class);

    expect(true)->toBeTrue();
});

it('V5: assertChildInvoked fails when child not invoked', function (): void {
    ChildPaymentMachine::fake(result: ['payment_id' => 'pay_v5']);

    $testMachine = TestMachine::create(ParentOrderMachine::class);
    // Never send START_PAYMENT - child is never invoked
    $testMachine->assertChildInvoked(ChildPaymentMachine::class);
})->throws(AssertionFailedError::class);

it('V6: assertChildNotInvoked passes when child not invoked', function (): void {
    ChildPaymentMachine::fake(result: ['payment_id' => 'pay_v6']);

    $testMachine = TestMachine::create(ParentOrderMachine::class);
    // Never send START_PAYMENT
    $testMachine->assertChildNotInvoked(ChildPaymentMachine::class);

    expect(true)->toBeTrue();
});

it('V7: assertChildInvokedTimes validates exact count', function (): void {
    ChildPaymentMachine::fake(result: ['payment_id' => 'pay_v7']);

    $testMachine = TestMachine::create(ParentOrderMachine::class);
    $testMachine->send('START_PAYMENT');
    $testMachine->assertChildInvokedTimes(ChildPaymentMachine::class, 1);

    expect(true)->toBeTrue();
});

it('V8: assertChildInvokedWith validates context subset', function (): void {
    ChildPaymentMachine::fake(result: ['payment_id' => 'pay_v8']);

    $testMachine = TestMachine::withContext(ParentOrderMachine::class, [
        'order_id'     => 'ORD-V8',
        'total_amount' => 3000,
    ]);
    $testMachine->send('START_PAYMENT');

    // ParentOrderMachine 'with' maps: 'order_id' => order_id, 'amount' => 'total_amount'
    $testMachine->assertChildInvokedWith(ChildPaymentMachine::class, ['order_id' => 'ORD-V8']);

    expect(true)->toBeTrue();
});

it('V9: assertChildInvokedWith fails on mismatch', function (): void {
    ChildPaymentMachine::fake(result: ['payment_id' => 'pay_v9']);

    $testMachine = TestMachine::create(ParentOrderMachine::class);
    $testMachine->send('START_PAYMENT');

    $testMachine->assertChildInvokedWith(ChildPaymentMachine::class, ['order_id' => 'NONEXISTENT']);
})->throws(AssertionFailedError::class);

it('V10: assertRoutedViaDoneState passes for matching route (@done.approved)', function (): void {
    MultiOutcomeChildMachine::fake(finalState: 'approved');

    $testMachine = TestMachine::define(
        config: [
            'id'      => 'v10_parent',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'delegating'],
                ],
                'delegating' => [
                    'machine'        => MultiOutcomeChildMachine::class,
                    '@done.approved' => 'completed',
                    '@done.rejected' => 'declined',
                    '@done'          => 'fallback',
                ],
                'completed' => ['type' => 'final'],
                'declined'  => ['type' => 'final'],
                'fallback'  => ['type' => 'final'],
            ],
        ],
    );

    $testMachine
        ->send('GO')
        ->assertState('completed')
        ->assertRoutedViaDoneState('approved');
});

it('V11: assertRoutedViaDoneState fails for catch-all (lastChildDoneRoute is null)', function (): void {
    MultiOutcomeChildMachine::fake();

    $testMachine = TestMachine::define(
        config: [
            'id'      => 'v11_parent',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'delegating'],
                ],
                'delegating' => [
                    'machine'        => MultiOutcomeChildMachine::class,
                    '@done.approved' => 'completed',
                    '@done'          => 'fallback',
                ],
                'completed' => ['type' => 'final'],
                'fallback'  => ['type' => 'final'],
            ],
        ],
    );

    $testMachine->send('GO')->assertState('fallback');

    // lastChildDoneRoute is null because catch-all @done fired,
    // so asserting 'approved' should fail
    $testMachine->assertRoutedViaDoneState('approved');
})->throws(AssertionFailedError::class);

// ═══════════════════════════════════════════════════════════════
//  Category 2: Async Simulation (7 tests)
// ═══════════════════════════════════════════════════════════════

it('V12: simulateChildDone transitions parent via @done', function (): void {
    Queue::fake();

    // Use a real Machine subclass with async delegation.
    // Queue::fake() swallows the ChildMachineJob dispatch,
    // keeping the parent in the 'processing' (delegating) state.
    $testMachine = TestMachine::create(AsyncParentMachine::class);

    $testMachine
        ->send(['type' => 'START', 'payload' => ['order_id' => 'ORD-12']])
        ->assertState('processing')
        ->simulateChildDone(SimpleChildMachine::class, result: ['status' => 'ok'])
        ->assertState('completed');
});

it('V13: simulateChildDone with finalState routes via @done.{state}', function (): void {
    Queue::fake();

    $testMachine = TestMachine::define(
        config: [
            'id'      => 'v13_parent',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'delegating'],
                ],
                'delegating' => [
                    'machine'        => MultiOutcomeChildMachine::class,
                    'queue'          => 'default',
                    '@done.approved' => 'accepted',
                    '@done.rejected' => 'declined',
                    '@done'          => 'fallback',
                ],
                'accepted' => ['type' => 'final'],
                'declined' => ['type' => 'final'],
                'fallback' => ['type' => 'final'],
            ],
        ],
    );

    // Set machineClass to avoid NOT NULL constraint on parent_machine_class in MachineChild
    $testMachine->machine()->definition->machineClass = 'InlineV13Parent';

    $testMachine
        ->send('GO')
        ->assertState('delegating')
        ->simulateChildDone(MultiOutcomeChildMachine::class, finalState: 'rejected')
        ->assertState('declined');
});

it('V14: simulateChildDone fails when not in delegating state', function (): void {
    $testMachine = TestMachine::define(
        config: [
            'id'      => 'v14_parent',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'active'],
                ],
                'active' => ['type' => 'final'],
            ],
        ],
    );

    // Stay in idle (no machine invoke definition)
    $testMachine->simulateChildDone(SimpleChildMachine::class);
})->throws(AssertionFailedError::class);

it('V15: simulateChildDone fails when wrong child class', function (): void {
    Queue::fake();

    $testMachine = TestMachine::define(
        config: [
            'id'      => 'v15_parent',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'delegating'],
                ],
                'delegating' => [
                    'machine' => SimpleChildMachine::class,
                    'queue'   => 'default',
                    '@done'   => 'completed',
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
    );

    $testMachine->machine()->definition->machineClass = 'InlineV15Parent';

    $testMachine
        ->send('GO')
        ->assertState('delegating')
        ->simulateChildDone(MultiOutcomeChildMachine::class);
})->throws(AssertionFailedError::class);

it('V16: simulateChildFail transitions parent via @fail', function (): void {
    Queue::fake();

    $testMachine = TestMachine::define(
        config: [
            'id'      => 'v16_parent',
            'initial' => 'idle',
            'context' => ['error' => null],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'delegating'],
                ],
                'delegating' => [
                    'machine' => FailingChildMachine::class,
                    'queue'   => 'default',
                    '@done'   => 'completed',
                    '@fail'   => [
                        'target'  => 'failed',
                        'actions' => 'captureErrorAction',
                    ],
                ],
                'completed' => ['type' => 'final'],
                'failed'    => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'captureErrorAction' => function (ContextManager $ctx, ChildMachineFailEvent $event): void {
                    $ctx->set('error', $event->errorMessage() ?? 'unknown');
                },
            ],
        ],
    );

    $testMachine->machine()->definition->machineClass = 'InlineV16Parent';

    $testMachine
        ->send('GO')
        ->assertState('delegating')
        ->simulateChildFail(FailingChildMachine::class, 'Gateway timeout')
        ->assertState('failed')
        ->assertContext('error', 'Gateway timeout');
});

it('V17: simulateChildTimeout transitions parent via @timeout', function (): void {
    Queue::fake();

    $testMachine = TestMachine::define(
        config: [
            'id'      => 'v17_parent',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'delegating'],
                ],
                'delegating' => [
                    'machine'  => SimpleChildMachine::class,
                    'queue'    => 'default',
                    '@done'    => 'completed',
                    '@timeout' => 'timed_out',
                ],
                'completed' => ['type' => 'final'],
                'timed_out' => ['type' => 'final'],
            ],
        ],
    );

    $testMachine->machine()->definition->machineClass = 'InlineV17Parent';

    $testMachine
        ->send('GO')
        ->assertState('delegating')
        ->simulateChildTimeout(SimpleChildMachine::class)
        ->assertState('timed_out');
});

it('V18: simulateChildDone result data accessible via output and result', function (): void {
    Queue::fake();

    $capturedOutput = null;
    $capturedResult = null;

    $testMachine = TestMachine::define(
        config: [
            'id'      => 'v18_parent',
            'initial' => 'idle',
            'context' => ['payment_id' => null],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'delegating'],
                ],
                'delegating' => [
                    'machine' => SimpleChildMachine::class,
                    'queue'   => 'default',
                    '@done'   => [
                        'target'  => 'completed',
                        'actions' => 'captureAction',
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'captureAction' => function (ContextManager $ctx, ChildMachineDoneEvent $event) use (&$capturedOutput, &$capturedResult): void {
                    $capturedOutput = $event->output('payment_id');
                    $capturedResult = $event->result('payment_id');
                    $ctx->set('payment_id', $capturedOutput);
                },
            ],
        ],
    );

    $testMachine->machine()->definition->machineClass = 'InlineV18Parent';

    $testMachine
        ->send('GO')
        ->assertState('delegating')
        ->simulateChildDone(SimpleChildMachine::class, result: ['payment_id' => 'pay_v18'])
        ->assertState('completed')
        ->assertContext('payment_id', 'pay_v18');

    expect($capturedOutput)->toBe('pay_v18');
    expect($capturedResult)->toBe('pay_v18');
});

// ═══════════════════════════════════════════════════════════════
//  Category 3: Cross-Machine Communication (8 tests)
// ═══════════════════════════════════════════════════════════════

it('V19: recordingCommunication enables recorder', function (): void {
    $testMachine = TestMachine::define(
        config: [
            'id'      => 'v19_machine',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => ['type' => 'final'],
            ],
        ],
    );

    expect(CommunicationRecorder::isRecording())->toBeFalse();

    $result = $testMachine->recordingCommunication();

    expect(CommunicationRecorder::isRecording())->toBeTrue();
    expect($result)->toBe($testMachine);
});

it('V20: assertSentTo passes when sendTo was called', function (): void {
    $testMachine = TestMachine::define(
        config: [
            'id'      => 'v20_machine',
            'initial' => 'idle',
            'context' => [
                'target_class'         => AsyncParentMachine::class,
                'target_root_event_id' => 'fake-root-event-id',
                'event_type'           => 'PING',
            ],
            'states' => [
                'idle' => [
                    'on' => ['NOTIFY' => ['target' => 'notified', 'actions' => SendToTargetAction::class]],
                ],
                'notified' => ['type' => 'final'],
            ],
        ],
    );

    $testMachine
        ->recordingCommunication()
        ->send('NOTIFY')
        ->assertSentTo(AsyncParentMachine::class);

    expect(true)->toBeTrue();
});

it('V21: assertSentTo with eventType filters correctly', function (): void {
    $testMachine = TestMachine::define(
        config: [
            'id'      => 'v21_machine',
            'initial' => 'idle',
            'context' => [
                'target_class'         => AsyncParentMachine::class,
                'target_root_event_id' => 'fake-root-event-id',
                'event_type'           => 'CUSTOM_EVENT',
            ],
            'states' => [
                'idle' => [
                    'on' => ['NOTIFY' => ['target' => 'notified', 'actions' => SendToTargetAction::class]],
                ],
                'notified' => ['type' => 'final'],
            ],
        ],
    );

    $testMachine
        ->recordingCommunication()
        ->send('NOTIFY')
        ->assertSentTo(AsyncParentMachine::class, 'CUSTOM_EVENT');

    expect(true)->toBeTrue();
});

it('V22: assertSentTo fails when sendTo not called', function (): void {
    $testMachine = TestMachine::define(
        config: [
            'id'      => 'v22_machine',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'done'],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
    );

    $testMachine
        ->recordingCommunication()
        ->send('GO')
        ->assertSentTo(AsyncParentMachine::class);
})->throws(AssertionFailedError::class);

it('V23: assertNotSentTo passes when no sendTo', function (): void {
    $testMachine = TestMachine::define(
        config: [
            'id'      => 'v23_machine',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'done'],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
    );

    $testMachine
        ->recordingCommunication()
        ->send('GO')
        ->assertNotSentTo(AsyncParentMachine::class);

    expect(true)->toBeTrue();
});

it('V24: assertDispatchedTo wraps Queue::assertPushed', function (): void {
    Queue::fake();

    $testMachine = TestMachine::define(
        config: [
            'id'      => 'v24_machine',
            'initial' => 'idle',
            'context' => [
                'target_class'         => AsyncParentMachine::class,
                'target_root_event_id' => 'fake-root-event-id',
                'event_type'           => 'ASYNC_EVENT',
            ],
            'states' => [
                'idle' => [
                    'on' => ['DISPATCH' => ['target' => 'dispatched', 'actions' => DispatchToTargetAction::class]],
                ],
                'dispatched' => ['type' => 'final'],
            ],
        ],
    );

    $testMachine
        ->send('DISPATCH')
        ->assertDispatchedTo(AsyncParentMachine::class, 'ASYNC_EVENT');
});

it('V25: assertDispatchedTo throws when Queue not faked', function (): void {
    // Do NOT call Queue::fake() - Queue::assertPushed will throw
    // because the Queue facade is not a QueueFake instance.
    $testMachine = TestMachine::define(
        config: [
            'id'      => 'v25_machine',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'done'],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
    );

    $testMachine
        ->send('GO')
        ->assertDispatchedTo(AsyncParentMachine::class);
})->throws(Error::class);

it('V26: assertRaisedEvent checks history for processed event', function (): void {
    $testMachine = TestMachine::define(
        config: [
            'id'      => 'v26_machine',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => [
                        'START' => ['target' => 'processing', 'actions' => RaiseRetryAction::class],
                    ],
                ],
                'processing' => [
                    'on' => [
                        'RETRY' => 'retrying',
                    ],
                ],
                'retrying' => ['type' => 'final'],
            ],
        ],
    );

    $testMachine
        ->recordingCommunication()
        ->send('START')
        ->assertRaisedEvent('RETRY')
        ->assertState('retrying');
});

// ═══════════════════════════════════════════════════════════════
//  Category 4: Forward Endpoint (2 tests)
// ═══════════════════════════════════════════════════════════════

it('V27: withRunningChild creates MachineChild record', function (): void {
    Queue::fake();

    // Create a persisted parent machine and transition to the delegating state.
    // Queue::fake() swallows the async dispatch, keeping parent in 'processing'.
    $parentMachine = AsyncParentMachine::create();
    $parentMachine->send([
        'type'    => 'START',
        'payload' => ['order_id' => 'ORD-V27'],
    ]);

    expect($parentMachine->state->currentStateDefinition->id)->toBe('async_parent.processing');

    $testMachine = TestMachine::for($parentMachine);
    $testMachine->withRunningChild(SimpleChildMachine::class);

    // Verify MachineChild record was created
    $parentRootEventId = $parentMachine->state->history->first()->root_event_id;
    $childRecord       = MachineChild::where('parent_root_event_id', $parentRootEventId)
        ->where('status', MachineChild::STATUS_RUNNING)
        ->first();

    expect($childRecord)->not->toBeNull();
    expect($childRecord->child_machine_class)->toBe(SimpleChildMachine::class);
    expect($parentMachine->state->hasActiveChildren())->toBeTrue();
});

it('V28: withRunningChild fails without persistence', function (): void {
    $testMachine = TestMachine::define(
        config: [
            'id'      => 'v28_parent',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'delegating'],
                ],
                'delegating' => [
                    'machine' => SimpleChildMachine::class,
                    'queue'   => 'default',
                    '@done'   => 'completed',
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
    );

    $testMachine->withRunningChild(SimpleChildMachine::class);
})->throws(RuntimeException::class);

// ═══════════════════════════════════════════════════════════════
//  Category 5: Infrastructure (3 tests)
// ═══════════════════════════════════════════════════════════════

it('V29: State::lastChildDoneRoute set by routeChildDoneEvent (direct routing test)', function (): void {
    // Build a machine definition with @done.approved routing
    $definition = MachineDefinition::define(config: [
        'id'      => 'v29_machine',
        'initial' => 'delegating',
        'context' => [],
        'states'  => [
            'delegating' => [
                'machine'        => MultiOutcomeChildMachine::class,
                '@done.approved' => 'completed',
                '@done.rejected' => 'declined',
                '@done'          => 'fallback',
            ],
            'completed' => ['type' => 'final'],
            'declined'  => ['type' => 'final'],
            'fallback'  => ['type' => 'final'],
        ],
    ]);

    // Build state manually to test routeChildDoneEvent in isolation
    // (bypassing getInitialState which now correctly invokes child machines)
    $stateDefinition = $definition->idMap['v29_machine.delegating'];
    $state           = State::forTesting(context: [], currentStateDefinition: $stateDefinition);

    // Route a @done.approved event directly
    $doneEvent = ChildMachineDoneEvent::forChild([
        'result'        => ['decision' => 'yes'],
        'output'        => ['decision' => 'yes'],
        'machine_id'    => '',
        'machine_class' => MultiOutcomeChildMachine::class,
        'final_state'   => 'approved',
    ]);

    $definition->routeChildDoneEvent($state, $stateDefinition, $doneEvent);

    expect($state->lastChildDoneRoute)->toBe('approved');
    expect($state->value)->toBe(['v29_machine.completed']);
});

it('V30: State::lastChildDoneRoute is null for catch-all @done', function (): void {
    $definition = MachineDefinition::define(config: [
        'id'      => 'v30_machine',
        'initial' => 'delegating',
        'context' => [],
        'states'  => [
            'delegating' => [
                'machine'        => MultiOutcomeChildMachine::class,
                '@done.approved' => 'completed',
                '@done'          => 'fallback',
            ],
            'completed' => ['type' => 'final'],
            'fallback'  => ['type' => 'final'],
        ],
    ]);

    // Build state manually to test routeChildDoneEvent in isolation
    $stateDefinition = $definition->idMap['v30_machine.delegating'];
    $state           = State::forTesting(context: [], currentStateDefinition: $stateDefinition);

    // Route with an unknown finalState that doesn't match any @done.{state}
    $doneEvent = ChildMachineDoneEvent::forChild([
        'result'        => [],
        'output'        => [],
        'machine_id'    => '',
        'machine_class' => MultiOutcomeChildMachine::class,
        'final_state'   => 'unknown',
    ]);

    $definition->routeChildDoneEvent($state, $stateDefinition, $doneEvent);

    expect($state->lastChildDoneRoute)->toBeNull();
    expect($state->value)->toBe(['v30_machine.fallback']);
});

it('V31: Machine::resetMachineFake clears single class only', function (): void {
    ChildPaymentMachine::fake(result: ['payment_id' => 'pay_v31']);
    SimpleChildMachine::fake(result: ['status' => 'ok']);

    expect(ChildPaymentMachine::isMachineFaked())->toBeTrue();
    expect(SimpleChildMachine::isMachineFaked())->toBeTrue();

    Machine::resetMachineFake(ChildPaymentMachine::class);

    expect(ChildPaymentMachine::isMachineFaked())->toBeFalse();
    expect(SimpleChildMachine::isMachineFaked())->toBeTrue();
});
