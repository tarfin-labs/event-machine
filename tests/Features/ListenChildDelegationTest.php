<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ListenerMachines\ChildWithListenMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ListenerMachines\MultiStateChildMachine;

// region Sync Child Delegation + Parent Listen

it('parent listen.entry fires on delegation state and @done target (sync child)', function (): void {
    $parentDef = MachineDefinition::define(
        config: [
            'id'      => 'listen_parent_sync',
            'initial' => 'idle',
            'context' => ['entryLog' => []],
            'listen'  => [
                'entry' => 'logEntryAction',
            ],
            'states' => [
                'idle'       => ['on' => ['START' => 'delegating']],
                'delegating' => [
                    'machine' => ImmediateChildMachine::class,
                    '@done'   => 'completed',
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'logEntryAction' => function (ContextManager $context): void {
                    $context->set('entryLog', [...$context->get('entryLog'), 'parent_entry']);
                },
            ],
        ],
    );

    $state = $parentDef->getInitialState();
    // Init: listen.entry fires on 'idle'
    expect($state->context->get('entryLog'))->toBe(['parent_entry']);

    $state = $parentDef->transition(['type' => 'START'], $state);
    // START → delegating (listen.entry) → child runs sync (immediate done) → @done → completed (listen.entry)
    // Total: idle + delegating + completed = 3 entries
    expect(count($state->context->get('entryLog')))->toBe(3);
});

it('parent listen does NOT fire on child machine internal state changes', function (): void {
    $parentDef = MachineDefinition::define(
        config: [
            'id'      => 'listen_isolation',
            'initial' => 'idle',
            'context' => ['parentEntryCount' => 0],
            'listen'  => [
                'entry' => 'countAction',
            ],
            'states' => [
                'idle'       => ['on' => ['START' => 'delegating']],
                'delegating' => [
                    'machine' => MultiStateChildMachine::class,
                    '@done'   => 'completed',
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'countAction' => function (ContextManager $context): void {
                    $context->set('parentEntryCount', $context->get('parentEntryCount') + 1);
                },
            ],
        ],
    );

    $state = $parentDef->getInitialState();
    expect($state->context->get('parentEntryCount'))->toBe(1); // idle

    $state = $parentDef->transition(['type' => 'START'], $state);
    // Child goes step_1 → step_2 → done (3 internal state changes)
    // Parent only sees: delegating entry + completed entry = 2 more = total 3
    // If parent listen fired on child states, count would be higher (5+)
    expect($state->context->get('parentEntryCount'))->toBe(3);
});

// endregion

// region Child Machine With Own Listen

it('child machine own listen fires independently of parent', function (): void {
    $parentDef = MachineDefinition::define(
        config: [
            'id'      => 'parent_child_own_listen',
            'initial' => 'idle',
            'context' => ['parentListenCount' => 0],
            'listen'  => [
                'entry' => 'parentCountAction',
            ],
            'states' => [
                'idle'       => ['on' => ['START' => 'delegating']],
                'delegating' => [
                    'machine' => ChildWithListenMachine::class,
                    '@done'   => 'completed',
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'parentCountAction' => function (ContextManager $context): void {
                    $context->set('parentListenCount', $context->get('parentListenCount') + 1);
                },
            ],
        ],
    );

    $state = $parentDef->getInitialState();
    expect($state->context->get('parentListenCount'))->toBe(1); // idle

    $state = $parentDef->transition(['type' => 'START'], $state);
    // Parent: idle(1) + delegating(2) + completed(3) = 3
    // Child's own listener fires inside child but doesn't affect parent context
    expect($state->context->get('parentListenCount'))->toBe(3);
});

// endregion

// region Listen + Delegation — Queued Dispatch

it('queued listen records dispatched event on delegation state entry', function (): void {
    $parentDef = MachineDefinition::define(
        config: [
            'id'      => 'listen_queued_deleg',
            'initial' => 'idle',
            'context' => [],
            'listen'  => [
                'entry' => [
                    'queuedAction' => ['queue' => true],
                ],
            ],
            'states' => [
                'idle'       => ['on' => ['START' => 'delegating']],
                'delegating' => [
                    'machine' => ImmediateChildMachine::class,
                    '@done'   => 'completed',
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'queuedAction' => function (): void {},
            ],
        ],
    );

    $state = $parentDef->getInitialState();
    $state = $parentDef->transition(['type' => 'START'], $state);

    $types = $state->history->pluck('type')->toArray();

    // Dispatched events for: idle, delegating, completed entries
    $dispatchedCount = count(array_filter($types, fn ($t) => str_contains($t, 'dispatched')));

    expect($dispatchedCount)->toBeGreaterThanOrEqual(3);
});

// endregion

// region Listen.transition + Delegation

it('listen.transition fires for delegation transition', function (): void {
    $parentDef = MachineDefinition::define(
        config: [
            'id'      => 'listen_trans_deleg',
            'initial' => 'idle',
            'context' => ['transitionCount' => 0],
            'listen'  => [
                'transition' => 'countTransitionAction',
            ],
            'states' => [
                'idle'       => ['on' => ['START' => 'delegating']],
                'delegating' => [
                    'machine' => ImmediateChildMachine::class,
                    '@done'   => 'completed',
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'countTransitionAction' => function (ContextManager $context): void {
                    $context->set('transitionCount', $context->get('transitionCount') + 1);
                },
            ],
        ],
    );

    $state = $parentDef->getInitialState();
    expect($state->context->get('transitionCount'))->toBe(0);

    $state = $parentDef->transition(['type' => 'START'], $state);
    // START triggers transition → listener fires at least once
    expect($state->context->get('transitionCount'))->toBeGreaterThanOrEqual(1);
});

// endregion
