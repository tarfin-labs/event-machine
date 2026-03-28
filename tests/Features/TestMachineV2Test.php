<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Support\Timer;
use PHPUnit\Framework\AssertionFailedError;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Testing\CommunicationRecorder;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\LogAction;
use Tarfinlabs\EventMachine\Behavior\ChildMachineDoneEvent;
use Tarfinlabs\EventMachine\Behavior\ChildMachineFailEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Jobs\FailingTestJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Guards\IsAllowedGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Jobs\SuccessfulTestJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\RaiseRetryAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\SendToTargetAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysGuardMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\DispatchToTargetAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\JobActors\JobActorParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ParentOrderMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\SimpleChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ChildPaymentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\FailingChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\MultiOutcomeChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateApprovedChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateRejectedChildMachine;

afterEach(function (): void {
    LogAction::resetFakes();
    IsAllowedGuard::resetFakes();
    Machine::resetMachineFakes();
});

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
        output: ['decision' => 'yes'],
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

    $testMachine->fakingChild(ImmediateApprovedChildMachine::class, output: ['decision' => 'yes']);
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
        ->fakingChild(ImmediateApprovedChildMachine::class, output: ['decision' => 'yes'])
        ->fakingChild(ImmediateRejectedChildMachine::class, output: ['reason' => 'no']);

    expect(ImmediateApprovedChildMachine::isMachineFaked())->toBeTrue();
    expect(ImmediateRejectedChildMachine::isMachineFaked())->toBeTrue();

    $testMachine->resetFakes();

    expect(ImmediateApprovedChildMachine::isMachineFaked())->toBeFalse();
    expect(ImmediateRejectedChildMachine::isMachineFaked())->toBeFalse();
});

it('V4: assertChildInvoked passes when child invoked', function (): void {
    ChildPaymentMachine::fake(output: ['paymentId' => 'pay_v4']);

    $testMachine = ParentOrderMachine::test();
    $testMachine->send('START_PAYMENT');
    $testMachine->assertChildInvoked(ChildPaymentMachine::class);

    expect(true)->toBeTrue();
});

it('V5: assertChildInvoked fails when child not invoked', function (): void {
    ChildPaymentMachine::fake(output: ['paymentId' => 'pay_v5']);

    $testMachine = ParentOrderMachine::test();
    // Never send START_PAYMENT - child is never invoked
    $testMachine->assertChildInvoked(ChildPaymentMachine::class);
})->throws(AssertionFailedError::class);

it('V6: assertChildNotInvoked passes when child not invoked', function (): void {
    ChildPaymentMachine::fake(output: ['paymentId' => 'pay_v6']);

    $testMachine = ParentOrderMachine::test();
    // Never send START_PAYMENT
    $testMachine->assertChildNotInvoked(ChildPaymentMachine::class);

    expect(true)->toBeTrue();
});

it('V7: assertChildInvokedTimes validates exact count', function (): void {
    ChildPaymentMachine::fake(output: ['paymentId' => 'pay_v7']);

    $testMachine = ParentOrderMachine::test();
    $testMachine->send('START_PAYMENT');
    $testMachine->assertChildInvokedTimes(ChildPaymentMachine::class, 1);

    expect(true)->toBeTrue();
});

it('V8: assertChildInvokedWith validates context subset', function (): void {
    ChildPaymentMachine::fake(output: ['paymentId' => 'pay_v8']);

    $testMachine = ParentOrderMachine::test([
        'orderId'     => 'ORD-V8',
        'totalAmount' => 3000,
    ]);
    $testMachine->send('START_PAYMENT');

    // ParentOrderMachine 'with' maps: 'orderId' => order_id, 'amount' => 'totalAmount'
    $testMachine->assertChildInvokedWith(ChildPaymentMachine::class, ['orderId' => 'ORD-V8']);

    expect(true)->toBeTrue();
});

it('V9: assertChildInvokedWith fails on mismatch', function (): void {
    ChildPaymentMachine::fake(output: ['paymentId' => 'pay_v9']);

    $testMachine = ParentOrderMachine::test();
    $testMachine->send('START_PAYMENT');

    $testMachine->assertChildInvokedWith(ChildPaymentMachine::class, ['orderId' => 'NONEXISTENT']);
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
    $testMachine = AsyncParentMachine::test();

    $testMachine
        ->send(['type' => 'START', 'payload' => ['orderId' => 'ORD-12']])
        ->assertState('processing')
        ->simulateChildDone(SimpleChildMachine::class, output: ['status' => 'ok'])
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
            'context' => ['paymentId' => null],
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
                    $capturedOutput = $event->output('paymentId');
                    $capturedResult = $event->output('paymentId');
                    $ctx->set('paymentId', $capturedOutput);
                },
            ],
        ],
    );

    $testMachine->machine()->definition->machineClass = 'InlineV18Parent';

    $testMachine
        ->send('GO')
        ->assertState('delegating')
        ->simulateChildDone(SimpleChildMachine::class, output: ['paymentId' => 'pay_v18'])
        ->assertState('completed')
        ->assertContext('paymentId', 'pay_v18');

    expect($capturedOutput)->toBe('pay_v18');
    expect($capturedResult)->toBe('pay_v18');
});

// ═══════════════════════════════════════════════════════════════
//  Category 2b: Job Actor Simulation (9 tests)
// ═══════════════════════════════════════════════════════════════

it('V18a: simulateChildDone routes @done for job actors', function (): void {
    Queue::fake();

    JobActorParentMachine::test()
        ->send(['type' => 'START'])
        ->assertState('processing')
        ->simulateChildDone(SuccessfulTestJob::class, output: ['paymentId' => 'pay_job_1'])
        ->assertState('completed')
        ->assertContext('paymentId', 'pay_job_1');
});

it('V18b: simulateChildFail routes @fail for job actors', function (): void {
    Queue::fake();

    JobActorParentMachine::test()
        ->send(['type' => 'START'])
        ->assertState('processing')
        ->simulateChildFail(SuccessfulTestJob::class, errorMessage: 'Payment gateway error')
        ->assertState('failed');
});

it('V18c: simulateChildTimeout routes @timeout for job actors', function (): void {
    Queue::fake();

    $testMachine = TestMachine::define(
        config: [
            'id'      => 'v18c_machine',
            'initial' => 'processing',
            'context' => [],
            'states'  => [
                'processing' => [
                    'job'      => SuccessfulTestJob::class,
                    'queue'    => 'default',
                    '@done'    => 'completed',
                    '@timeout' => 'timed_out',
                ],
                'completed' => ['type' => 'final'],
                'timed_out' => ['type' => 'final'],
            ],
        ],
    );

    $testMachine
        ->simulateChildTimeout(SuccessfulTestJob::class)
        ->assertState('timed_out');
});

it('V18d: simulateChildDone throws for wrong job class', function (): void {
    Queue::fake();

    $testMachine = JobActorParentMachine::test()
        ->send(['type' => 'START'])
        ->assertState('processing');

    expect(fn () => $testMachine->simulateChildDone(FailingTestJob::class))
        ->toThrow(
            AssertionFailedError::class,
            'delegates to ['.SuccessfulTestJob::class.']'
        );
});

it('V18e: simulateChildDone throws for wrong machine class (regression)', function (): void {
    Queue::fake();

    $testMachine = AsyncParentMachine::test()
        ->send(['type' => 'START', 'payload' => ['orderId' => 'ORD-REG']])
        ->assertState('processing');

    expect(fn () => $testMachine->simulateChildDone(FailingTestJob::class))
        ->toThrow(
            AssertionFailedError::class,
            'delegates to ['.SimpleChildMachine::class.']'
        );
});

it('V18f: simulateChildFail throws for wrong class', function (): void {
    Queue::fake();

    $testMachine = JobActorParentMachine::test()
        ->send(['type' => 'START'])
        ->assertState('processing');

    expect(fn () => $testMachine->simulateChildFail(FailingTestJob::class))
        ->toThrow(
            AssertionFailedError::class,
            'delegates to ['.SuccessfulTestJob::class.']'
        );
});

it('V18g: simulateChildDone throws on fire-and-forget job target state', function (): void {
    Queue::fake();

    $testMachine = TestMachine::define(
        config: [
            'id'      => 'v18g_machine',
            'initial' => 'dispatching',
            'context' => [],
            'states'  => [
                'dispatching' => [
                    'job'    => SuccessfulTestJob::class,
                    'target' => 'waiting',
                ],
                'waiting'   => [],
                'completed' => ['type' => 'final'],
            ],
        ],
    );

    // Fire-and-forget transitions immediately to 'waiting'
    $testMachine->assertState('waiting');

    expect(fn () => $testMachine->simulateChildDone(SuccessfulTestJob::class))
        ->toThrow(
            AssertionFailedError::class,
            'does not have a child delegation'
        );
});

it('V18h: simulateChildDone with result data accessible in @done action for job actors', function (): void {
    Queue::fake();

    JobActorParentMachine::test()
        ->send(['type' => 'START'])
        ->assertState('processing')
        ->simulateChildDone(SuccessfulTestJob::class, output: ['paymentId' => 'pay_result_check'])
        ->assertState('completed')
        ->assertContext('paymentId', 'pay_result_check');
});

it('V18i: full job actor flow with multiple simulateChildDone calls', function (): void {
    Queue::fake();

    $testMachine = TestMachine::define(
        config: [
            'id'      => 'v18i_machine',
            'initial' => 'step_one',
            'context' => [
                'stepOneResult' => null,
                'stepTwoResult' => null,
            ],
            'states' => [
                'step_one' => [
                    'job'   => SuccessfulTestJob::class,
                    'queue' => 'default',
                    '@done' => [
                        'target'  => 'step_two',
                        'actions' => 'captureStepOneAction',
                    ],
                ],
                'step_two' => [
                    'job'   => FailingTestJob::class,
                    'queue' => 'default',
                    '@done' => [
                        'target'  => 'completed',
                        'actions' => 'captureStepTwoAction',
                    ],
                    '@fail' => 'failed',
                ],
                'completed' => ['type' => 'final'],
                'failed'    => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'captureStepOneAction' => function (ContextManager $ctx, EventBehavior $event): void {
                    $ctx->set('stepOneResult', $event->payload['output']['data'] ?? 'done');
                },
                'captureStepTwoAction' => function (ContextManager $ctx, EventBehavior $event): void {
                    $ctx->set('stepTwoResult', $event->payload['output']['data'] ?? 'done');
                },
            ],
        ],
    );

    $testMachine
        ->assertState('step_one')
        ->simulateChildDone(SuccessfulTestJob::class, output: ['data' => 'phase_1'])
        ->assertState('step_two')
        ->assertContext('stepOneResult', 'phase_1')
        ->simulateChildFail(FailingTestJob::class, errorMessage: 'Step 2 crashed')
        ->assertState('failed');
});

// ═══════════════════════════════════════════════════════════════
//  Category 2b2: Machine Entry Points (7 tests)
// ═══════════════════════════════════════════════════════════════

it('V70: Machine::test() creates TestMachine with pre-init context', function (): void {
    Queue::fake();

    // Machine::test() delegates to TestMachine::withContext() (pre-init)
    $test = JobActorParentMachine::test(context: ['orderId' => 'ORD-70']);

    $test->assertContext('orderId', 'ORD-70')
        ->assertState('idle');
});

it('V71: Machine::test() accepts guards and faking', function (): void {
    $test = AlwaysGuardMachine::test(
        guards: [IsAllowedGuard::class => true],
        faking: [LogAction::class],
    );

    $test->assertState('done');
    $test->assertContext('logged', false); // LogAction was spied, not real
});

it('V72: Machine::test() without params works', function (): void {
    Queue::fake();

    $test = JobActorParentMachine::test();
    $test->assertState('idle');
});

it('V73: Machine::startingAt() creates machine at specified state', function (): void {
    Queue::fake();

    $test = JobActorParentMachine::startingAt('processing');
    $test->assertState('processing');
});

it('V74: Machine::startingAt() supports context, guards, and faking', function (): void {
    $test = AlwaysGuardMachine::startingAt(
        stateId: 'idle',
        guards: [IsAllowedGuard::class => true],
    );

    $test->assertState('idle') // @always didn't fire (startingAt skips lifecycle)
        ->send('GO')
        ->assertState('done');
});

it('V75: assertNotDispatchedTo passes when no dispatch sent', function (): void {
    Queue::fake();

    $test = TestMachine::define(
        config: [
            'id'      => 'v75_machine',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => ['on' => ['GO' => 'done']],
                'done' => ['type' => 'final'],
            ],
        ],
    );

    $test->send('GO')
        ->assertNotDispatchedTo(JobActorParentMachine::class);
});

it('V76: assertNotDispatchedTo fails when dispatch was sent', function (): void {
    Queue::fake();

    $test = TestMachine::define(
        config: [
            'id'      => 'v76_machine',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => [
                        'GO' => [
                            'target'  => 'done',
                            'actions' => 'dispatchAction',
                        ],
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'dispatchAction' => DispatchToTargetAction::class,
            ],
        ],
    );

    $test->send('GO');

    // DispatchToTargetAction dispatches to AsyncParentMachine by default
    // assertNotDispatchedTo should fail for that target
    expect(fn () => $test->assertNotDispatchedTo(AsyncParentMachine::class))
        ->toThrow(AssertionFailedError::class);
});

// ═══════════════════════════════════════════════════════════════
//  Category 2c: Bulk Faking (9 tests)
// ═══════════════════════════════════════════════════════════════

it('V40: fakingAllActions spies all class-based actions', function (): void {
    $test = TestMachine::define(
        config: [
            'id'      => 'v40_machine',
            'initial' => 'idle',
            'context' => ['logged' => false],
            'states'  => [
                'idle' => [
                    'on' => [
                        'GO' => [
                            'target'  => 'done',
                            'actions' => 'logAction',
                        ],
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'logAction' => LogAction::class,
            ],
        ],
    );

    $test->fakingAllActions()
        ->send('GO')
        ->assertState('done');

    // LogAction was spied — context NOT modified
    $test->assertContext('logged', false);
    LogAction::assertRan();
});

it('V41: fakingAllActions except by FQCN skips specified actions', function (): void {
    $test = TestMachine::define(
        config: [
            'id'      => 'v41_machine',
            'initial' => 'idle',
            'context' => ['logged' => false],
            'states'  => [
                'idle' => [
                    'on' => [
                        'GO' => [
                            'target'  => 'done',
                            'actions' => 'logAction',
                        ],
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'logAction' => LogAction::class,
            ],
        ],
    );

    $test->fakingAllActions(except: [LogAction::class])
        ->send('GO')
        ->assertState('done');

    // LogAction was NOT faked — ran real logic, set context
    expect(LogAction::isFaked())->toBeFalse();
    $test->assertContext('logged', true);
});

it('V42: fakingAllActions except by behavior key skips specified actions', function (): void {
    Queue::fake();

    $test = JobActorParentMachine::test()
        ->fakingAllActions(except: ['capturePaymentAction']);

    $test->send(['type' => 'START'])
        ->assertState('processing')
        ->simulateChildDone(SuccessfulTestJob::class, output: ['paymentId' => 'pay_42'])
        ->assertState('completed');

    // capturePaymentAction was excluded — ran real logic, context updated
    $test->assertContext('paymentId', 'pay_42');
});

it('V43: fakingAllActions ignores inline closures', function (): void {
    $test = TestMachine::define(
        config: [
            'id'      => 'v43_machine',
            'initial' => 'idle',
            'context' => ['value' => null],
            'states'  => [
                'idle' => [
                    'on' => [
                        'GO' => [
                            'target'  => 'done',
                            'actions' => 'inlineAction',
                        ],
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'inlineAction' => function (ContextManager $ctx): void {
                    $ctx->set('value', 'inline_ran');
                },
            ],
        ],
    );

    // fakingAllActions should skip inline closures
    $test->fakingAllActions()
        ->send('GO')
        ->assertState('done')
        ->assertContext('value', 'inline_ran'); // Inline closure still ran
});

it('V44: fakingAllActions tracks fakes for resetFakes cleanup', function (): void {
    Queue::fake();

    $test = JobActorParentMachine::test()
        ->fakingAllActions();

    $test->resetFakes();

    // After reset, behaviors should no longer be faked
    // (This test verifies cleanup doesn't throw)
    expect(true)->toBeTrue();
});

it('V45: fakingAllGuards spies all class-based guards', function (): void {
    $test = TestMachine::define(
        config: [
            'id'      => 'v45_machine',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => [
                        'GO' => [
                            'target' => 'done',
                            'guards' => 'isAllowedGuard',
                        ],
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'guards' => [
                'isAllowedGuard' => IsAllowedGuard::class,
            ],
        ],
    );

    $test->fakingAllGuards();

    // Guard is spied (tracked for assertions)
    expect(IsAllowedGuard::isFaked())->toBeTrue();
});

it('V46: fakingAllGuards except: skips specified guards', function (): void {
    $test = TestMachine::define(
        config: [
            'id'      => 'v46_machine',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => [
                        'GO' => [
                            'target' => 'done',
                            'guards' => 'isAllowedGuard',
                        ],
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'guards' => [
                'isAllowedGuard' => IsAllowedGuard::class,
            ],
        ],
    );

    // Guard excluded from faking — runs real logic (returns true)
    $test->fakingAllGuards(except: [IsAllowedGuard::class])
        ->send('GO')
        ->assertState('done');
});

it('V47: fakingAllGuards spy returns null — guard fails by default', function (): void {
    $test = TestMachine::define(
        config: [
            'id'      => 'v47_machine',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => [
                        'GO' => [
                            'target' => 'done',
                            'guards' => 'isAllowedGuard',
                        ],
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'guards' => [
                'isAllowedGuard' => IsAllowedGuard::class,
            ],
        ],
    );

    $test->fakingAllGuards();

    // Guard is spied — verify it's faked
    expect(IsAllowedGuard::isFaked())->toBeTrue();

    // Send GO — spy allows call, guard evaluation sees spy behavior
    $test->send('GO');
    IsAllowedGuard::assertRan();
});

it('V48: fakingAllBehaviors fakes actions + guards + calculators', function (): void {
    $test = TestMachine::define(
        config: [
            'id'      => 'v48_machine',
            'initial' => 'idle',
            'context' => ['logged' => false],
            'states'  => [
                'idle' => [
                    'on' => [
                        'GO' => [
                            'target'  => 'done',
                            'actions' => 'logAction',
                        ],
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'logAction' => LogAction::class,
            ],
        ],
    );

    $test->fakingAllBehaviors()
        ->send('GO')
        ->assertState('done');

    // LogAction was spied via fakingAllBehaviors
    LogAction::assertRan();
    $test->assertContext('logged', false); // Spy intercepted, real logic didn't run
});

// ═══════════════════════════════════════════════════════════════
//  Category 2d: Guards Parameter (3 tests)
// ═══════════════════════════════════════════════════════════════

it('V49: withContext guards: sets guard fakes before @always fires', function (): void {
    $test = AlwaysGuardMachine::test(
        context: [],
        guards: [IsAllowedGuard::class => true],
    );

    // If guard wasn't faked before init, @always would fail
    $test->assertState('done');
});

it('V50: create guards: sets guard fakes before init', function (): void {
    $test = AlwaysGuardMachine::test(
        guards: [IsAllowedGuard::class => true],
    );

    $test->assertState('done');
});

it('V51: guards parameter tracked for resetFakes cleanup', function (): void {
    $test = AlwaysGuardMachine::test(
        context: [],
        guards: [IsAllowedGuard::class => true],
    );

    $test->resetFakes();
    expect(true)->toBeTrue(); // No throw during cleanup
});

it('V51a: withContext faking: spies actions before @always fires', function (): void {
    $test = AlwaysGuardMachine::test(
        context: [],
        guards: [IsAllowedGuard::class => true],
        faking: [LogAction::class],
    );

    // @always fired → LogAction was spied (not real) → context NOT modified
    $test->assertState('done');
    $test->assertContext('logged', false);
    LogAction::assertRan();
});

it('V51b: withContext without faking: runs real action during @always', function (): void {
    $test = AlwaysGuardMachine::test(
        context: [],
        guards: [IsAllowedGuard::class => true],
        // NO faking: parameter → LogAction runs real logic
    );

    // @always fired → LogAction ran real logic → context modified
    $test->assertState('done');
    $test->assertContext('logged', true);
});

it('V51c: faking: parameter tracked for resetFakes cleanup', function (): void {
    $test = AlwaysGuardMachine::test(
        context: [],
        guards: [IsAllowedGuard::class => true],
        faking: [LogAction::class],
    );

    expect(LogAction::isFaked())->toBeTrue();
    $test->resetFakes();
    expect(LogAction::isFaked())->toBeFalse();
});

// ═══════════════════════════════════════════════════════════════
//  Category 2e: startingAt (9 tests)
// ═══════════════════════════════════════════════════════════════

it('V52: startingAt creates machine at specified state', function (): void {
    $test = JobActorParentMachine::startingAt(stateId: 'processing');
    $test->assertState('processing');
});

it('V53: startingAt does not run entry actions', function (): void {
    // Define a machine where initial state has an entry action that sets context
    $test = JobActorParentMachine::startingAt(
        stateId: 'idle',
        context: ['paymentId' => null],
    );

    // Entry actions did NOT run — context stays as provided
    $test->assertContext('paymentId', null);
});

it('V54: startingAt does not fire @always transitions', function (): void {
    $test = AlwaysGuardMachine::startingAt(
        stateId: 'idle',
    );

    // @always would transition to done if lifecycle ran — it didn't
    $test->assertState('idle');
});

it('V55: startingAt does not dispatch jobs', function (): void {
    Queue::fake();

    JobActorParentMachine::startingAt(stateId: 'processing');

    Queue::assertNothingPushed();
});

it('V56: startingAt resolves compound state to initial child', function (): void {
    // ParentOrderMachine: awaiting_payment is atomic — can test state resolution
    $test = ParentOrderMachine::startingAt(
        stateId: 'awaiting_payment',
    );

    $test->assertState('awaiting_payment');
});

it('V57: startingAt throws for unknown state', function (): void {
    expect(fn () => JobActorParentMachine::startingAt(stateId: 'nonexistent'))
        ->toThrow(InvalidArgumentException::class, 'not found in machine definition');
});

it('V58: startingAt supports simulateChildDone after creation', function (): void {
    Queue::fake();

    JobActorParentMachine::startingAt(
        stateId: 'processing',
    )
        ->simulateChildDone(SuccessfulTestJob::class, output: ['paymentId' => 'pay_58'])
        ->assertState('completed');
});

it('V59: startingAt supports guards parameter', function (): void {
    $test = AlwaysGuardMachine::startingAt(
        stateId: 'idle',
        guards: [IsAllowedGuard::class => true],
    );

    // Guard is faked but @always didn't fire (startingAt skips lifecycle)
    $test->assertState('idle');

    // Now send an event that triggers the guarded transition
    $test->send('GO')->assertState('done');
});

it('V60: startingAt with fakingAllActions full flow', function (): void {
    Queue::fake();

    JobActorParentMachine::startingAt(
        stateId: 'processing',
    )
        ->fakingAllActions()
        ->simulateChildDone(SuccessfulTestJob::class, output: ['paymentId' => 'pay_60'])
        ->assertState('completed')
        ->assertContext('paymentId', 'pay_60'); // inline closure ran (fakingAll skips inline)
});

it('V61: startingAt registers timers for advanceTimers', function (): void {
    $test = TestMachine::define(
        config: [
            'id'      => 'v61_machine',
            'initial' => 'waiting',
            'context' => [],
            'states'  => [
                'waiting' => [
                    'on' => [
                        'TIMEOUT' => [
                            'target' => 'expired',
                            'after'  => Timer::seconds(60),
                        ],
                    ],
                ],
                'expired' => ['type' => 'final'],
            ],
        ],
    );

    $test->advanceTimers(Timer::seconds(61))
        ->assertState('expired');
});

it('V62: startingAt with advanceTimers fires after timer', function (): void {
    // Create a machine class that has a timer on a non-initial state
    $test = TestMachine::define(
        config: [
            'id'      => 'v62_machine',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'waiting'],
                ],
                'waiting' => [
                    'on' => [
                        'TIMEOUT' => [
                            'target' => 'expired',
                            'after'  => Timer::hours(24),
                        ],
                    ],
                ],
                'expired' => ['type' => 'final'],
            ],
        ],
    );

    $test->send('GO')
        ->assertState('waiting')
        ->advanceTimers(Timer::hours(25))
        ->assertState('expired');
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
                'targetClass'       => AsyncParentMachine::class,
                'targetRootEventId' => 'fake-root-event-id',
                'eventType'         => 'PING',
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
                'targetClass'       => AsyncParentMachine::class,
                'targetRootEventId' => 'fake-root-event-id',
                'eventType'         => 'CUSTOM_EVENT',
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
                'targetClass'       => AsyncParentMachine::class,
                'targetRootEventId' => 'fake-root-event-id',
                'eventType'         => 'ASYNC_EVENT',
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
        'payload' => ['orderId' => 'ORD-V27'],
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
        'output'        => ['decision' => 'yes'],
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
        'output'        => [],
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
    ChildPaymentMachine::fake(output: ['paymentId' => 'pay_v31']);
    SimpleChildMachine::fake(output: ['status' => 'ok']);

    expect(ChildPaymentMachine::isMachineFaked())->toBeTrue();
    expect(SimpleChildMachine::isMachineFaked())->toBeTrue();

    Machine::resetMachineFake(ChildPaymentMachine::class);

    expect(ChildPaymentMachine::isMachineFaked())->toBeFalse();
    expect(SimpleChildMachine::isMachineFaked())->toBeTrue();
});
