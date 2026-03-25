<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\GrandchildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\MiddleChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ThreeLevelParentMachine;

// ============================================================
// Three-Level Nested Delegation Chain
// ============================================================
// Parent delegates to child, child delegates to grandchild.
// Using simulateChildDone for grandchild then child, verifying
// the entire chain resolves correctly.

it('three-level chain: grandchild done propagates through child to parent', function (): void {
    Queue::fake();

    // Step 1: Simulate grandchild completing in the middle child
    $middleTest = MiddleChildMachine::test()
        ->send(['type' => 'START'])
        ->assertState('delegating')
        ->simulateChildDone(GrandchildMachine::class)
        ->assertState('completed');

    // Step 2: Parent delegates to middle child, simulate middle child done
    ThreeLevelParentMachine::test()
        ->send(['type' => 'START'])
        ->assertState('processing')
        ->simulateChildDone(MiddleChildMachine::class, result: ['chain' => 'complete'])
        ->assertState('completed')
        ->assertContext('result', ['chain' => 'complete']);
});

it('three-level chain: grandchild failure propagates via child fail to parent', function (): void {
    Queue::fake();

    // Middle child routes grandchild failure to its own failed state
    MiddleChildMachine::test()
        ->send(['type' => 'START'])
        ->assertState('delegating')
        ->simulateChildFail(GrandchildMachine::class, errorMessage: 'grandchild error')
        ->assertState('failed');

    // Parent handles middle child failure
    ThreeLevelParentMachine::test()
        ->send(['type' => 'START'])
        ->assertState('processing')
        ->simulateChildFail(MiddleChildMachine::class, errorMessage: 'child propagated error')
        ->assertState('failed')
        ->assertContext('error', 'child propagated error');
});

it('three-level chain: startingAt processing then simulateChildDone completes parent', function (): void {
    Queue::fake();

    ThreeLevelParentMachine::startingAt(stateId: 'processing')
        ->simulateChildDone(MiddleChildMachine::class, result: ['level' => 3, 'data' => 'deep'])
        ->assertState('completed')
        ->assertContext('result', ['level' => 3, 'data' => 'deep']);
});

it('three-level inline chain with custom machines and result propagation', function (): void {
    Queue::fake();

    $testMachine = TestMachine::define(
        config: [
            'id'      => 'inline_three_level',
            'initial' => 'idle',
            'context' => [
                'grandchild_data' => null,
                'chain_depth'     => 0,
            ],
            'states' => [
                'idle' => [
                    'on' => ['BEGIN' => 'level_one'],
                ],
                'level_one' => [
                    'machine' => MiddleChildMachine::class,
                    'queue'   => 'default',
                    '@done'   => [
                        'target'  => 'level_one_done',
                        'actions' => 'captureLevelOneAction',
                    ],
                    '@fail' => 'chain_failed',
                ],
                'level_one_done' => ['type' => 'final'],
                'chain_failed'   => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'captureLevelOneAction' => function (ContextManager $ctx, EventBehavior $event): void {
                    $ctx->set('grandchild_data', $event->payload['result'] ?? null);
                    $ctx->set('chain_depth', 3);
                },
            ],
        ],
    );

    $testMachine->machine()->definition->machineClass = 'InlineThreeLevelParent';

    $testMachine
        ->send('BEGIN')
        ->assertState('level_one')
        ->simulateChildDone(MiddleChildMachine::class, result: ['origin' => 'grandchild'])
        ->assertState('level_one_done')
        ->assertContext('grandchild_data', ['origin' => 'grandchild'])
        ->assertContext('chain_depth', 3);
});
