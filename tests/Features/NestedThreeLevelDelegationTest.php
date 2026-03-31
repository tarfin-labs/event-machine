<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\GrandchildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\MiddleChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ThreeLevelParentMachine;

// ============================================================
// Nested Three-Level Child Machine Delegation Chain
// ============================================================
// Parent -> Child -> Grandchild.
// When grandchild completes, child completes, then parent transitions.

it('parent delegates to middle child via simulateChildDone', function (): void {
    Queue::fake();

    ThreeLevelParentMachine::test()
        ->send(['type' => 'START'])
        ->assertState('processing')
        ->simulateChildDone(MiddleChildMachine::class, output: ['status' => 'ok'])
        ->assertState('completed')
        ->assertContext('childOutput', ['status' => 'ok']);
});

it('parent delegates to middle child and handles failure via simulateChildFail', function (): void {
    Queue::fake();

    ThreeLevelParentMachine::test()
        ->send(['type' => 'START'])
        ->assertState('processing')
        ->simulateChildFail(MiddleChildMachine::class, errorMessage: 'Grandchild crashed')
        ->assertState('failed')
        ->assertContext('error', 'Grandchild crashed');
});

it('middle child delegates to grandchild via simulateChildDone', function (): void {
    Queue::fake();

    MiddleChildMachine::test()
        ->send(['type' => 'START'])
        ->assertState('delegating')
        ->simulateChildDone(GrandchildMachine::class)
        ->assertState('completed');
});

it('middle child routes to failed when grandchild fails', function (): void {
    Queue::fake();

    MiddleChildMachine::test()
        ->send(['type' => 'START'])
        ->assertState('delegating')
        ->simulateChildFail(GrandchildMachine::class, errorMessage: 'Deep failure')
        ->assertState('failed');
});

it('sync three-level delegation completes when all children are immediate', function (): void {
    // Three-level sync delegation: parent → middle → grandchild, all immediate.
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'sync_three_level',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['START' => 'delegating'],
                ],
                'delegating' => [
                    // Middle machine that itself delegates to ImmediateChildMachine (grandchild)
                    'machine' => ImmediateChildMachine::class,
                    '@done'   => 'completed',
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(event: ['type' => 'START'], state: $state);

    expect($state->value)->toBe(['sync_three_level.completed']);
});

it('inline three-level delegation: parent → child → grandchild all sync immediate', function (): void {
    // Create a grandchild-like machine that's immediate
    // Middle machine delegates to ImmediateChildMachine
    // Parent machine delegates to the middle machine

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'inline_three_level',
            'initial' => 'processing',
            'context' => ['depth' => 0],
            'states'  => [
                'processing' => [
                    // This delegates to ImmediateChildMachine which starts in final state
                    'machine' => ImmediateChildMachine::class,
                    '@done'   => [
                        'target'  => 'completed',
                        'actions' => 'incrementDepthAction',
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'incrementDepthAction' => function (ContextManager $ctx): void {
                    $ctx->set('depth', $ctx->get('depth') + 1);
                },
            ],
        ],
    );

    $state = $definition->getInitialState();

    // Parent starts, delegates to ImmediateChildMachine (immediate final),
    // @done fires, parent transitions to completed.
    expect($state->value)->toBe(['inline_three_level.completed'])
        ->and($state->context->get('depth'))->toBe(1);
});

it('async three-level: startingAt processing then simulateChildDone', function (): void {
    Queue::fake();

    ThreeLevelParentMachine::startingAt(stateId: 'processing')
        ->simulateChildDone(MiddleChildMachine::class, output: ['paymentId' => 'pay_nested'])
        ->assertState('completed')
        ->assertContext('childOutput', ['paymentId' => 'pay_nested']);
});

it('async parent correctly routes @done with result data from nested chain', function (): void {
    Queue::fake();

    $testMachine = ThreeLevelParentMachine::test();

    $testMachine
        ->send(['type' => 'START'])
        ->assertState('processing')
        ->simulateChildDone(MiddleChildMachine::class, output: [
            'grandchild_output' => 'deep_value',
            'level'             => 3,
        ])
        ->assertState('completed')
        ->assertContext('childOutput', [
            'grandchild_output' => 'deep_value',
            'level'             => 3,
        ]);
});

it('inline three-level async with TestMachine::define for parent and child simulation', function (): void {
    Queue::fake();

    $testMachine = TestMachine::define(
        config: [
            'id'      => 'inline_nested_async',
            'initial' => 'idle',
            'context' => ['childOutput' => null],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'delegating_to_middle'],
                ],
                'delegating_to_middle' => [
                    'machine' => MiddleChildMachine::class,
                    'queue'   => 'default',
                    '@done'   => [
                        'target'  => 'completed',
                        'actions' => 'captureAction',
                    ],
                    '@fail' => 'failed',
                ],
                'completed' => ['type' => 'final'],
                'failed'    => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'captureAction' => function (ContextManager $ctx, EventBehavior $event): void {
                    $ctx->set('childOutput', $event->payload['output'] ?? null);
                },
            ],
        ],
    );

    $testMachine->machine()->definition->machineClass = 'InlineNestedAsyncParent';

    $testMachine
        ->send('GO')
        ->assertState('delegating_to_middle')
        ->simulateChildDone(MiddleChildMachine::class, output: ['nested' => true])
        ->assertState('completed')
        ->assertContext('childOutput', ['nested' => true]);
});
