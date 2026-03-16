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
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ResultChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\SimpleChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ChildPaymentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\FailingChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ContextMutatingChildMachine;

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
                'order_id'          => 'ORD-123',
                'amount'            => 5000,
                'received_order_id' => null,
            ],
            'states' => [
                'start'      => ['on' => ['GO' => 'processing']],
                'processing' => [
                    'machine' => ChildPaymentMachine::class,
                    'with'    => ['order_id', 'amount'],
                    '@done'   => [
                        'target'  => 'done',
                        'actions' => 'captureResultAction',
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'captureResultAction' => function (ContextManager $context, EventBehavior $event): void {
                    $context->set('received_order_id', $event->payload['output']['order_id'] ?? null);
                },
            ],
        ],
    );

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    expect($state->value)->toBe(['parent_with.done'])
        ->and($state->context->get('received_order_id'))->toBe('ORD-123');
});

it('transfers context to child via with key mapping format', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'parent_mapped',
            'initial' => 'start',
            'context' => [
                'total_price'     => 9999,
                'child_got_price' => null,
            ],
            'states' => [
                'start'      => ['on' => ['GO' => 'processing']],
                'processing' => [
                    'machine' => ChildPaymentMachine::class,
                    'with'    => ['amount' => 'total_price'],
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
                    $ctx->set('child_got_price', $event->payload['result']['amount'] ?? null);
                },
            ],
        ],
    );

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    expect($state->value)->toBe(['parent_mapped.done'])
        ->and($state->context->get('child_got_price'))->toBe(9999);
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
                'order_id' => 'ORIGINAL',
            ],
            'states' => [
                'idle'       => ['on' => ['GO' => 'processing']],
                'processing' => [
                    'machine' => ContextMutatingChildMachine::class,
                    'with'    => ['order_id'],
                    '@done'   => 'done',
                ],
                'done' => ['type' => 'final'],
            ],
        ],
    );

    $state = $machine->getInitialState();
    $state = $machine->transition(event: ['type' => 'GO'], state: $state);

    // Parent's order_id must be untouched
    expect($state->context->get('order_id'))->toBe('ORIGINAL');
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
                    'machine' => ResultChildMachine::class,
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
                    return ($event->payload['result']['status'] ?? '') === 'approved';
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
                    'machine' => ResultChildMachine::class,
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
        ->and($receivedEvent->result('payment_id'))->toBe('pay_abc')
        ->and($receivedEvent->output('payment_id'))->toBe('pay_abc')
        ->and($receivedEvent->childMachineClass())->toBe(ResultChildMachine::class);
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
        ->and($receivedEvent->output('payment_id'))->toBe('pay_xyz')
        ->and($receivedEvent->output('status'))->toBe('approved')
        ->and($receivedEvent->output('internal_retry_count'))->toBeNull() // not exposed
        ->and($receivedEvent->output())->toBe(['payment_id' => 'pay_xyz', 'status' => 'approved']);
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
                    'machine' => ResultChildMachine::class,
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

    // ResultChildMachine has no output key → full context returned (payment_id, status)
    expect($receivedEvent)->toBeInstanceOf(ChildMachineDoneEvent::class)
        ->and($receivedEvent->output())->toBeArray()
        ->and($receivedEvent->output('payment_id'))->toBe('pay_abc')
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

it('uses ParentOrderMachine stub for full lifecycle', function (): void {
    $machine = ParentOrderMachine::create();

    // Set context values before sending event
    $machine->state->context->set('order_id', 'ORD-456');
    $machine->state->context->set('total_amount', 7500);

    $state = $machine->send(['type' => 'START_PAYMENT']);

    expect($state->currentStateDefinition->id)->toBe('parent_order.completed')
        ->and($state->context->get('payment_id'))->not->toBeNull()
        ->and($state->context->get('receipt_url'))->not->toBeNull();
});
