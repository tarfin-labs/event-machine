<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Behavior\ChildMachineDoneEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\OutputChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ParentOrderMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\SimpleChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ChildPaymentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\FailingChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\FinalOutputChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ContextMutatingChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateApprovedChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateRejectedChildMachine;

// ============================================================
// Basic Sync Machine Delegation Lifecycle
// ============================================================

it('delegates to a child machine and transitions via @done on completion', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'parent',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['START' => 'delegating'],
                ],
                'delegating' => [
                    'machine' => ImmediateChildMachine::class,
                    '@done'   => 'completed',
                ],
                'completed' => [
                    'type' => 'final',
                ],
            ],
        ],
    );

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'START'], state: $state);

    expect($state->value)->toBe(['parent.completed']);
});

it('transfers context to child via with array (same-name format)', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'parent_with',
            'initial' => 'start',
            'context' => [
                'orderId'         => 'ORD-123',
                'amount'          => 5000,
                'receivedOrderId' => null,
            ],
            'states' => [
                'start'      => ['on' => ['GO' => 'processing']],
                'processing' => [
                    'machine' => ChildPaymentMachine::class,
                    'input'   => ['orderId', 'amount'],
                    '@done'   => [
                        'target'  => 'done',
                        'actions' => 'captureOutputAction',
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'captureOutputAction' => function (ContextManager $context, EventBehavior $event): void {
                    $context->set('receivedOrderId', $event->payload['output']['amount'] ?? null);
                },
            ],
        ],
    );

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    expect($state->value)->toBe(['parent_with.done'])
        ->and($state->context->get('receivedOrderId'))->toBe(5000);
});

it('transfers context to child via with key mapping format', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'parent_mapped',
            'initial' => 'start',
            'context' => [
                'totalPrice'    => 9999,
                'childGotPrice' => null,
            ],
            'states' => [
                'start'      => ['on' => ['GO' => 'processing']],
                'processing' => [
                    'machine' => ChildPaymentMachine::class,
                    'input'   => ['amount' => 'totalPrice'],
                    '@done'   => [
                        'target'  => 'done',
                        'actions' => 'storeAction',
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'storeAction' => function (ContextManager $ctx, EventBehavior $event): void {
                    $ctx->set('childGotPrice', $event->payload['output']['amount'] ?? null);
                },
            ],
        ],
    );

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    expect($state->value)->toBe(['parent_mapped.done'])
        ->and($state->context->get('childGotPrice'))->toBe(9999);
});

it('routes to @fail when child machine throws exception', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'parent_fail',
            'initial' => 'idle',
            'context' => ['error' => null],
            'states'  => [
                'idle'       => ['on' => ['GO' => 'processing']],
                'processing' => [
                    'machine' => FailingChildMachine::class,
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
                'captureErrorAction' => function (ContextManager $ctx, EventBehavior $event): void {
                    $ctx->set('error', $event->payload['error_message'] ?? 'unknown');
                },
            ],
        ],
    );

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    expect($state->value)->toBe(['parent_fail.failed'])
        ->and($state->context->get('error'))->toBe('Payment gateway down');
});

it('re-throws exception when no @fail is defined', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'parent_nofail',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle'       => ['on' => ['GO' => 'processing']],
                'processing' => [
                    'machine' => FailingChildMachine::class,
                    '@done'   => 'completed',
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
    );

    $state = $machine->getInitialState();

    expect(fn () => $machine->transition(event: ['type' => 'GO'], state: $state))
        ->toThrow(RuntimeException::class, 'Payment gateway down');
});

// ============================================================
// Machine Identity Injection
// ============================================================

it('returns null from machineId() when identity has not been set', function (): void {
    $context = ContextManager::from(['data' => ['key' => 'value']]);

    expect($context->machineId())->toBeNull();
});

it('injects _machine_id accessible via machineId() on every machine', function (): void {
    $capturedId = null;

    $machine = MachineDefinition::define(
        config: [
            'id'      => 'identity_test',
            'initial' => 'start',
            'context' => [],
            'states'  => [
                'start' => [
                    'entry' => 'captureIdAction',
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'captureIdAction' => function (ContextManager $ctx) use (&$capturedId): void {
                    $capturedId = $ctx->machineId();
                },
            ],
        ],
    );

    $state = $machine->getInitialState();

    expect($capturedId)->not->toBeNull()
        ->and($capturedId)->toBeString();
});

it('isChildMachine returns false for standalone machines', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'standalone',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [],
            ],
        ],
    );

    $state = $machine->getInitialState();
    expect($state->context->isChildMachine())->toBeFalse();
});

it('delegates to child and parent completes via @done', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'identity_parent',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle'       => ['on' => ['GO' => 'delegating']],
                'delegating' => [
                    'machine' => ImmediateChildMachine::class,
                    '@done'   => 'completed',
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
    );

    $state           = $machine->getInitialState();
    $parentMachineId = $state->context->machineId();
    $state           = $machine->transition(event: ['type' => 'GO'], state: $state);

    expect($state->value)->toBe(['identity_parent.completed'])
        ->and($parentMachineId)->toBeString();
});

// ============================================================
// Context & State Isolation
// ============================================================

it('child context changes do not affect parent context', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'isolation_parent',
            'initial' => 'idle',
            'context' => [
                'orderId' => 'ORIGINAL',
            ],
            'states' => [
                'idle'       => ['on' => ['GO' => 'processing']],
                'processing' => [
                    'machine' => ContextMutatingChildMachine::class,
                    'input'   => ['orderId'],
                    '@done'   => 'done',
                ],
                'done' => ['type' => 'final'],
            ],
        ],
    );

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    // Parent's order_id must be untouched
    expect($state->context->get('orderId'))->toBe('ORIGINAL');
});

it('parent state value does not include child states', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'state_isolation',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle'       => ['on' => ['GO' => 'delegating']],
                'delegating' => [
                    'machine' => ImmediateChildMachine::class,
                    '@done'   => 'finished',
                ],
                'finished' => ['type' => 'final'],
            ],
        ],
    );

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    // Parent should be in finished, never in child states
    expect($state->value)->toBe(['state_isolation.finished'])
        ->and($state->value)->not->toContain('immediate_child.done');
});

// ============================================================
// @done/@fail Multi-Branch Guarded Fork
// ============================================================

it('supports @done multi-branch guarded fork', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'guarded_done',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle'       => ['on' => ['GO' => 'processing']],
                'processing' => [
                    'machine' => FinalOutputChildMachine::class,
                    '@done'   => [
                        ['target' => 'approved', 'guards' => 'isApprovedGuard'],
                        ['target' => 'review'],
                    ],
                ],
                'approved' => ['type' => 'final'],
                'review'   => ['type' => 'final'],
            ],
        ],
        behavior: [
            'guards' => [
                'isApprovedGuard' => function (ContextManager $ctx, EventBehavior $event): bool {
                    return ($event->payload['output']['status'] ?? '') === 'approved';
                },
            ],
        ],
    );

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    expect($state->value)->toBe(['guarded_done.approved']);
});

it('supports @fail multi-branch guarded fork', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'guarded_fail',
            'initial' => 'idle',
            'context' => ['retries' => 0],
            'states'  => [
                'idle'       => ['on' => ['GO' => 'processing']],
                'processing' => [
                    'machine' => FailingChildMachine::class,
                    '@done'   => 'completed',
                    '@fail'   => [
                        ['target' => 'retrying', 'guards' => 'canRetryGuard'],
                        ['target' => 'failed'],
                    ],
                ],
                'completed' => ['type' => 'final'],
                'retrying'  => ['type' => 'final'],
                'failed'    => ['type' => 'final'],
            ],
        ],
        behavior: [
            'guards' => [
                'canRetryGuard' => function (ContextManager $ctx): bool {
                    return $ctx->get('retries') < 3;
                },
            ],
        ],
    );

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    // retries is 0 < 3, so canRetry is true → goes to retrying
    expect($state->value)->toBe(['guarded_fail.retrying']);
});

// ============================================================
// Edge Cases
// ============================================================

it('handles immediate final child (sync-completing)', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'imm_parent',
            'initial' => 'start',
            'context' => [],
            'states'  => [
                'start'      => ['on' => ['GO' => 'delegating']],
                'delegating' => [
                    'machine' => ImmediateChildMachine::class,
                    '@done'   => 'completed',
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
    );

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    expect($state->value)->toBe(['imm_parent.completed']);
});

it('ChildMachineDoneEvent has typed accessors', function (): void {
    $receivedEvent = null;

    $machine = MachineDefinition::define(
        config: [
            'id'      => 'accessor_parent',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle'       => ['on' => ['GO' => 'processing']],
                'processing' => [
                    'machine' => FinalOutputChildMachine::class,
                    '@done'   => [
                        'target'  => 'done',
                        'actions' => 'captureEventAction',
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'captureEventAction' => function (ContextManager $ctx, EventBehavior $event) use (&$receivedEvent): void {
                    $receivedEvent = $event;
                },
            ],
        ],
    );

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    expect($receivedEvent)->toBeInstanceOf(ChildMachineDoneEvent::class)
        ->and($receivedEvent->output('paymentId'))->toBe('pay_abc')
        ->and($receivedEvent->childMachineClass())->toBe(FinalOutputChildMachine::class);
});

// ─── Output Filtering ────────────────────────────────────────────

it('output key filters child context to only specified keys', function (): void {
    $receivedEvent = null;

    $machine = MachineDefinition::define(
        config: [
            'id'      => 'output_parent',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'processing'],
                ],
                'processing' => [
                    'machine' => OutputChildMachine::class,
                    '@done'   => [
                        'target'  => 'done',
                        'actions' => 'captureOutputAction',
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'captureOutputAction' => function (ContextManager $context, ChildMachineDoneEvent $event) use (&$receivedEvent): void {
                    $receivedEvent = $event;
                },
            ],
        ],
    );

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    expect($receivedEvent)->toBeInstanceOf(ChildMachineDoneEvent::class)
        ->and($receivedEvent->output('paymentId'))->toBe('pay_xyz')
        ->and($receivedEvent->output('status'))->toBe('approved')
        ->and($receivedEvent->output('internal_retry_count'))->toBeNull() // not exposed
        ->and($receivedEvent->output())->toBe(['paymentId' => 'pay_xyz', 'status' => 'approved']);
});

it('output falls back to full context when no output key defined', function (): void {
    $receivedEvent = null;

    $machine = MachineDefinition::define(
        config: [
            'id'      => 'no_output_parent',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'processing'],
                ],
                'processing' => [
                    'machine' => FinalOutputChildMachine::class,
                    '@done'   => [
                        'target'  => 'done',
                        'actions' => 'captureOutputAction',
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'captureOutputAction' => function (ContextManager $context, ChildMachineDoneEvent $event) use (&$receivedEvent): void {
                    $receivedEvent = $event;
                },
            ],
        ],
    );

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    // FinalOutputChildMachine has no output key → full context returned (payment_id, status)
    expect($receivedEvent)->toBeInstanceOf(ChildMachineDoneEvent::class)
        ->and($receivedEvent->output())->toBeArray()
        ->and($receivedEvent->output('paymentId'))->toBe('pay_abc')
        ->and($receivedEvent->output('status'))->toBe('approved');
});

it('validates machine + parallel type mutual exclusivity', function (): void {
    expect(fn () => MachineDefinition::define(
        config: [
            'id'      => 'invalid',
            'initial' => 'test',
            'states'  => [
                'test' => [
                    'type'    => 'parallel',
                    'machine' => SimpleChildMachine::class,
                    'states'  => [
                        'region1' => ['initial' => 'a', 'states' => ['a' => []]],
                    ],
                ],
            ],
        ],
    ))->toThrow(
        InvalidArgumentException::class,
        "cannot have both 'machine' and type 'parallel'"
    );
});

it('validates machine value must be a string', function (): void {
    expect(fn () => MachineDefinition::define(
        config: [
            'id'      => 'invalid',
            'initial' => 'test',
            'states'  => [
                'test' => [
                    'machine' => 12345,
                ],
            ],
        ],
    ))->toThrow(
        InvalidArgumentException::class,
        'Must be a string'
    );
});

it('validates forward requires queue', function (): void {
    expect(fn () => MachineDefinition::define(
        config: [
            'id'      => 'invalid',
            'initial' => 'test',
            'states'  => [
                'test' => [
                    'machine' => SimpleChildMachine::class,
                    'forward' => ['SOME_EVENT'],
                ],
            ],
        ],
    ))->toThrow(
        InvalidArgumentException::class,
        "has 'forward' without 'queue'"
    );
});

// ============================================================
// @done.{finalState} — Declarative Final State Routing
// ============================================================

it('routes to correct target based on child final state key via @done.{state} (T1)', function (): void {
    // Parent with child that auto-completes in 'approved'
    $machineA = MachineDefinition::define(config: [
        'id'     => 'parent_a', 'initial' => 'idle', 'context' => [],
        'states' => [
            'idle'       => ['on' => ['GO' => 'delegating']],
            'delegating' => [
                'machine'        => ImmediateApprovedChildMachine::class,
                '@done.approved' => 'target_a',
                '@done.rejected' => 'target_b',
            ],
            'target_a' => ['type' => 'final'],
            'target_b' => ['type' => 'final'],
        ],
    ]);

    $state = $machineA->getInitialState();
    $state = $machineA->transition(event: ['type' => 'GO'], state: $state);
    expect($state->value)->toBe(['parent_a.target_a']);

    // Parent with child that auto-completes in 'rejected'
    $machineB = MachineDefinition::define(config: [
        'id'     => 'parent_b', 'initial' => 'idle', 'context' => [],
        'states' => [
            'idle'       => ['on' => ['GO' => 'delegating']],
            'delegating' => [
                'machine'        => ImmediateRejectedChildMachine::class,
                '@done.approved' => 'target_a',
                '@done.rejected' => 'target_b',
            ],
            'target_a' => ['type' => 'final'],
            'target_b' => ['type' => 'final'],
        ],
    ]);

    $state = $machineB->getInitialState();
    $state = $machineB->transition(event: ['type' => 'GO'], state: $state);
    expect($state->value)->toBe(['parent_b.target_b']);
});

it('@done.{state} supports config with actions (T2)', function (): void {
    $capturedFinalState = null;

    $machine = MachineDefinition::define(
        config: [
            'id'     => 'action_parent', 'initial' => 'idle', 'context' => ['childDecision' => null],
            'states' => [
                'idle'       => ['on' => ['GO' => 'delegating']],
                'delegating' => [
                    'machine'        => ImmediateApprovedChildMachine::class,
                    '@done.approved' => ['target' => 'completed', 'actions' => 'storeDecisionAction'],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'storeDecisionAction' => function (ContextManager $ctx, ChildMachineDoneEvent $event) use (&$capturedFinalState): void {
                    $ctx->set('childDecision', $event->output('decision'));
                    $capturedFinalState = $event->finalState();
                },
            ],
        ],
    );

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    expect($state->value)->toBe(['action_parent.completed'])
        ->and($state->context->get('childDecision'))->toBe('yes')
        ->and($capturedFinalState)->toBe('approved');
});

it('falls through to @done catch-all when child reaches unmatched final state (T3)', function (): void {
    // ImmediateChildMachine completes in 'done' — not matched by @done.approved
    $machine = MachineDefinition::define(config: [
        'id'     => 'fallback_parent', 'initial' => 'idle', 'context' => [],
        'states' => [
            'idle'       => ['on' => ['GO' => 'delegating']],
            'delegating' => [
                'machine'        => ImmediateChildMachine::class,
                '@done.approved' => 'completed',
                '@done'          => 'fallback',
            ],
            'completed' => ['type' => 'final'],
            'fallback'  => ['type' => 'final'],
        ],
    ]);

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    expect($state->value)->toBe(['fallback_parent.fallback']);
});

it('@done.{state} works without catch-all when final state matches (T6)', function (): void {
    $machine = MachineDefinition::define(config: [
        'id'     => 'no_catchall', 'initial' => 'idle', 'context' => [],
        'states' => [
            'idle'       => ['on' => ['GO' => 'delegating']],
            'delegating' => [
                'machine'        => ImmediateApprovedChildMachine::class,
                '@done.approved' => 'completed',
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    expect($state->value)->toBe(['no_catchall.completed']);
});

// ============================================================
// Existing Tests
// ============================================================

it('uses ParentOrderMachine stub for full lifecycle', function (): void {
    $machine = ParentOrderMachine::create();

    // Set context values before sending event
    $machine->state->context->set('orderId', 'ORD-456');
    $machine->state->context->set('totalAmount', 7500);

    $state = $machine->send(['type' => 'START_PAYMENT']);

    expect($state->currentStateDefinition->id)->toBe('parent_order.completed')
        ->and($state->context->get('paymentId'))->not->toBeNull()
        ->and($state->context->get('receiptUrl'))->not->toBeNull();
});

// ============================================================
// @done.{finalState} — Guards, Accessors, Coexistence
// ============================================================

it('guard on specific @done.{state} receives ChildMachineDoneEvent (T4)', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'     => 'guarded_dot', 'initial' => 'idle', 'context' => [],
            'states' => [
                'idle'       => ['on' => ['GO' => 'delegating']],
                'delegating' => [
                    'machine'        => ImmediateApprovedChildMachine::class,
                    '@done.approved' => [
                        ['target' => 'vip', 'guards' => 'hasDecisionGuard'],
                        ['target' => 'standard'],
                    ],
                ],
                'vip'      => ['type' => 'final'],
                'standard' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'guards' => [
                'hasDecisionGuard' => function (ContextManager $ctx, ChildMachineDoneEvent $event): bool {
                    return $event->output('decision') === 'yes';
                },
            ],
        ],
    );

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    expect($state->value)->toBe(['guarded_dot.vip']);
});

it('guard fallthrough from @done.{state} falls to @done catch-all (T5)', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'     => 'fallthrough', 'initial' => 'idle', 'context' => [],
            'states' => [
                'idle'       => ['on' => ['GO' => 'delegating']],
                'delegating' => [
                    'machine'        => ImmediateApprovedChildMachine::class,
                    '@done.approved' => [
                        ['target' => 'vip', 'guards' => 'alwaysFailGuard'],
                    ],
                    '@done' => 'standard',
                ],
                'vip'      => ['type' => 'final'],
                'standard' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'guards' => [
                'alwaysFailGuard' => fn (): bool => false,
            ],
        ],
    );

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    expect($state->value)->toBe(['fallthrough.standard']);
});

it('ChildMachineDoneEvent.finalState() returns key not full ID (T7)', function (): void {
    $capturedFinalState = null;

    $machine = MachineDefinition::define(
        config: [
            'id'     => 'accessor_test', 'initial' => 'idle', 'context' => [],
            'states' => [
                'idle'       => ['on' => ['GO' => 'delegating']],
                'delegating' => [
                    'machine'        => ImmediateApprovedChildMachine::class,
                    '@done.approved' => ['target' => 'completed', 'actions' => 'captureAction'],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'captureAction' => function (ContextManager $ctx, ChildMachineDoneEvent $event) use (&$capturedFinalState): void {
                    $capturedFinalState = $event->finalState();
                },
            ],
        ],
    );

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    // Must be 'approved' (key), not 'immediate_approved.approved' (full ID)
    expect($capturedFinalState)->toBe('approved');
});

it('ChildMachineDoneEvent.finalState() returns null for legacy events (T8)', function (): void {
    $event = ChildMachineDoneEvent::forChild([
        'output'        => null,
        'output'        => [],
        'machine_id'    => 'test-123',
        'machine_class' => ImmediateChildMachine::class,
        // No 'final_state' key — legacy event
    ]);

    expect($event->finalState())->toBeNull();
});

it('@done.{state} coexists with @fail independently (T9)', function (): void {
    $machine = MachineDefinition::define(config: [
        'id'     => 'coexist', 'initial' => 'idle', 'context' => [],
        'states' => [
            'idle'       => ['on' => ['GO' => 'delegating']],
            'delegating' => [
                'machine'        => FailingChildMachine::class,
                '@done.approved' => 'completed',
                '@done.rejected' => 'declined',
                '@fail'          => 'error',
            ],
            'completed' => ['type' => 'final'],
            'declined'  => ['type' => 'final'],
            'error'     => ['type' => 'final'],
        ],
    ]);

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    // FailingChildMachine throws → @fail routes to error
    expect($state->value)->toBe(['coexist.error']);
});

it('@done.{state} action receives output, result, and finalState together (T10)', function (): void {
    $capturedOutput     = null;
    $capturedOutput     = null;
    $capturedFinalState = null;

    $machine = MachineDefinition::define(
        config: [
            'id'     => 'all_accessors', 'initial' => 'idle', 'context' => [],
            'states' => [
                'idle'       => ['on' => ['GO' => 'delegating']],
                'delegating' => [
                    'machine'    => FinalOutputChildMachine::class,
                    '@done.done' => ['target' => 'completed', 'actions' => 'captureAllAction'],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'captureAllAction' => function (ContextManager $ctx, ChildMachineDoneEvent $event) use (&$capturedOutput, &$capturedFinalState): void {
                    $capturedOutput     = $event->output('status');
                    $capturedFinalState = $event->finalState();
                },
            ],
        ],
    );

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    expect($state->value)->toBe(['all_accessors.completed'])
        ->and($capturedOutput)->toBe('approved')
        ->and($capturedFinalState)->toBe('done');
});

// ============================================================
// @done.{finalState} — Edge Cases
// ============================================================

it('multiple @done.{state} keys with same target cause no conflict (T32)', function (): void {
    $machine = MachineDefinition::define(config: [
        'id'     => 'same_target', 'initial' => 'idle', 'context' => [],
        'states' => [
            'idle'       => ['on' => ['GO' => 'delegating']],
            'delegating' => [
                'machine'        => ImmediateRejectedChildMachine::class,
                '@done.approved' => 'error',
                '@done.rejected' => 'error',
            ],
            'error' => ['type' => 'final'],
        ],
    ]);

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    expect($state->value)->toBe(['same_target.error']);
});

it('@done.{state} on state with on transitions work independently (T33)', function (): void {
    $machine = MachineDefinition::define(config: [
        'id'     => 'mixed_events', 'initial' => 'idle', 'context' => [],
        'states' => [
            'idle'       => ['on' => ['GO' => 'delegating']],
            'delegating' => [
                'machine'        => ImmediateApprovedChildMachine::class,
                '@done.approved' => 'completed',
                'on'             => ['CANCEL' => 'cancelled'],
            ],
            'completed' => ['type' => 'final'],
            'cancelled' => ['type' => 'final'],
        ],
    ]);

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    // Child auto-completes, @done.approved fires
    expect($state->value)->toBe(['mixed_events.completed']);
});

it('@done.{state} with calculators runs calculator before guard (T31)', function (): void {
    $calculatorRan = false;

    $machine = MachineDefinition::define(
        config: [
            'id'     => 'calc_test', 'initial' => 'idle', 'context' => ['total' => 0],
            'states' => [
                'idle'       => ['on' => ['GO' => 'delegating']],
                'delegating' => [
                    'machine'        => ImmediateApprovedChildMachine::class,
                    '@done.approved' => [
                        'target'      => 'completed',
                        'calculators' => 'computeTotalCalculator',
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'calculators' => [
                'computeTotalCalculator' => function (ContextManager $ctx, ChildMachineDoneEvent $event) use (&$calculatorRan): void {
                    $calculatorRan = true;
                    $ctx->set('total', 42);
                },
            ],
        ],
    );

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    expect($state->value)->toBe(['calc_test.completed'])
        ->and($calculatorRan)->toBeTrue()
        ->and($state->context->get('total'))->toBe(42);
});
