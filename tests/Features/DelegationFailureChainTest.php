<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\FailingChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\MidLevelNoFailMachine;

// ============================================================
// Delegation Failure Chain Tests (XState Pass 4)
// ============================================================

// ─── Test 1: Grandparent fail chain ────────────────────────────

it('propagates @fail through 3-level delegation chain from grandchild to grandparent', function (): void {
    // Chain: Grandparent → MidLevelNoFailMachine → FailingChildMachine (throws)
    // MidLevelNoFailMachine has no @fail, so RuntimeException re-throws through it.
    // Grandparent catches via its own @fail handler.

    $grandparentDefinition = MachineDefinition::define(
        config: [
            'id'      => 'grandparent',
            'initial' => 'idle',
            'context' => ['error' => null],
            'states'  => [
                'idle'       => ['on' => ['START' => 'delegating']],
                'delegating' => [
                    'machine' => MidLevelNoFailMachine::class,
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

    $state = $grandparentDefinition->getInitialState();
    $state = $grandparentDefinition->transition(event: ['type' => 'START'], state: $state);

    // Grandparent caught the failure that propagated through mid-level
    expect($state->value)->toBe(['grandparent.failed'])
        ->and($state->context->get('error'))->toBe('Payment gateway down');
});

// ─── Test 2: Handled vs unhandled fail ─────────────────────────

it('parent with @fail handler stays active after handling, without handler exception propagates', function (): void {
    // WITH @fail: parent transitions to failed state gracefully
    $handledDefinition = MachineDefinition::define(
        config: [
            'id'      => 'handled_parent',
            'initial' => 'idle',
            'context' => ['errorHandled' => false],
            'states'  => [
                'idle'       => ['on' => ['GO' => 'processing']],
                'processing' => [
                    'machine' => FailingChildMachine::class,
                    '@done'   => 'completed',
                    '@fail'   => [
                        'target'  => 'error_handled',
                        'actions' => 'markHandledAction',
                    ],
                ],
                'completed'     => ['type' => 'final'],
                'error_handled' => [],
            ],
        ],
        behavior: [
            'actions' => [
                'markHandledAction' => function (ContextManager $ctx): void {
                    $ctx->set('errorHandled', true);
                },
            ],
        ],
    );

    $state = $handledDefinition->getInitialState();
    $state = $handledDefinition->transition(event: ['type' => 'GO'], state: $state);

    // Parent handled the failure and is in error_handled (non-final) state
    expect($state->value)->toBe(['handled_parent.error_handled'])
        ->and($state->context->get('errorHandled'))->toBeTrue();

    // WITHOUT @fail: exception propagates
    $unhandledDefinition = MachineDefinition::define(
        config: [
            'id'      => 'unhandled_parent',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle'       => ['on' => ['GO' => 'processing']],
                'processing' => [
                    'machine' => FailingChildMachine::class,
                    '@done'   => 'completed',
                    // No @fail
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
    );

    $state2 = $unhandledDefinition->getInitialState();

    expect(fn () => $unhandledDefinition->transition(event: ['type' => 'GO'], state: $state2))
        ->toThrow(RuntimeException::class, 'Payment gateway down');
});

// ─── Test 3: Final entry sendToParent before @done ─────────────

it('child final state entry action runs sendToParent before @done fires to parent', function (): void {
    $executionOrder = [];

    // Define parent inline — captures when @done action runs
    $parentDefinition = MachineDefinition::define(
        config: [
            'id'      => 'order_parent',
            'initial' => 'idle',
            'context' => [
                'childNotified' => false,
                'doneReceived'  => false,
            ],
            'states' => [
                'idle'       => ['on' => ['GO' => 'delegating']],
                'delegating' => [
                    'machine' => ImmediateChildMachine::class,
                    '@done'   => [
                        'target'  => 'completed',
                        'actions' => 'markDoneAction',
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'markDoneAction' => function (ContextManager $ctx) use (&$executionOrder): void {
                    $executionOrder[] = 'parent_done_action';
                    $ctx->set('doneReceived', true);
                },
            ],
        ],
    );

    $state = $parentDefinition->getInitialState();
    $state = $parentDefinition->transition(event: ['type' => 'GO'], state: $state);

    // Verify parent received @done and transitioned
    expect($state->value)->toBe(['order_parent.completed'])
        ->and($state->context->get('doneReceived'))->toBeTrue()
        ->and($executionOrder)->toContain('parent_done_action');
});
