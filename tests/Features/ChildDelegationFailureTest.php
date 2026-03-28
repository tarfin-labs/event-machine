<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\AssertionFailedError;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\SimpleChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\FailingChildMachine;

uses(RefreshDatabase::class);

// ============================================================
// Test 1: child-done-after-timeout
// After @timeout fires and parent moves to timed_out,
// simulateChildDone must be rejected (parent no longer in delegation state).
// ============================================================

it('ignores @done after @timeout — parent stays in timed_out', function (): void {
    Queue::fake();

    $testMachine = TestMachine::define(
        config: [
            'id'      => 'done_after_timeout',
            'initial' => 'idle',
            'context' => [
                'output'  => null,
                'timeout' => false,
            ],
            'states' => [
                'idle' => [
                    'on' => ['START' => 'processing'],
                ],
                'processing' => [
                    'machine' => SimpleChildMachine::class,
                    'queue'   => 'child-queue',
                    '@done'   => [
                        'target'  => 'completed',
                        'actions' => 'captureResultAction',
                    ],
                    '@timeout' => [
                        'target'  => 'timed_out',
                        'timeout' => 30,
                        'actions' => 'captureTimeoutAction',
                    ],
                ],
                'completed' => ['type' => 'final'],
                'timed_out' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'captureResultAction' => function (ContextManager $ctx): void {
                    $ctx->set('childOutput', 'child_completed');
                },
                'captureTimeoutAction' => function (ContextManager $ctx): void {
                    $ctx->set('timeout', true);
                },
            ],
        ],
    );

    $testMachine->machine()->definition->machineClass = 'InlineDoneAfterTimeout';

    // Step 1: Send START → parent enters delegation state (async child, faked queue)
    $testMachine->send('START')->assertState('processing');

    // Step 2: Simulate timeout → parent moves to timed_out
    $testMachine
        ->simulateChildTimeout(SimpleChildMachine::class)
        ->assertState('timed_out')
        ->assertContext('timeout', true);

    // Step 3: Attempt simulateChildDone — parent is no longer in a delegation state,
    // so this must throw AssertionFailedError (cannot simulate done on non-delegation state).
    expect(fn () => $testMachine->simulateChildDone(SimpleChildMachine::class, ['status' => 'ok']))
        ->toThrow(AssertionFailedError::class);

    // Parent stays in timed_out, result unchanged
    $testMachine
        ->assertState('timed_out')
        ->assertContext('childOutput', null);
});

// ============================================================
// Test 2: fail-all-guards-false
// Child fails, parent has @fail with guarded branches, ALL guards
// return false and no default branch. Exception must re-throw.
// ============================================================

it('re-throws exception when all @fail guard branches reject', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'fail_all_guards_false',
            'initial' => 'idle',
            'context' => [
                'retries' => 10,
            ],
            'states' => [
                'idle' => [
                    'on' => ['GO' => 'processing'],
                ],
                'processing' => [
                    'machine' => FailingChildMachine::class,
                    '@done'   => 'completed',
                    '@fail'   => [
                        ['target' => 'retrying', 'guards' => 'canRetryGuard'],
                        ['target' => 'escalated', 'guards' => 'isEscalatableGuard'],
                    ],
                ],
                'completed' => ['type' => 'final'],
                'retrying'  => ['type' => 'final'],
                'escalated' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'guards' => [
                'canRetryGuard' => function (ContextManager $ctx): bool {
                    return $ctx->get('retries') < 3;
                },
                'isEscalatableGuard' => function (ContextManager $ctx): bool {
                    return $ctx->get('retries') < 5;
                },
            ],
        ],
    );

    $state = $machine->getInitialState();

    // retries=10 → canRetryGuard fails (10 >= 3), isEscalatableGuard fails (10 >= 5)
    // No default (unguarded) branch exists → exception must re-throw
    expect(fn () => $machine->transition(event: ['type' => 'GO'], state: $state))
        ->toThrow(RuntimeException::class, 'Payment gateway down');
});
