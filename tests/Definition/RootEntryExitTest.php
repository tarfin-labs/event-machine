<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;

// region Root entry

it('runs root entry actions on machine initialization', function (): void {
    TestMachine::define([
        'id'      => 'root_entry_test',
        'initial' => 'idle',
        'context' => ['root_entered' => false],
        'entry'   => 'markRootEntryAction',
        'states'  => [
            'idle' => [
                'on' => ['GO' => 'active'],
            ],
            'active' => ['type' => 'final'],
        ],
    ], behavior: [
        'context' => GenericContext::class,
        'actions' => [
            'markRootEntryAction' => function (ContextManager $context): void {
                $context->set('root_entered', true);
            },
        ],
    ])
        ->assertContext('root_entered', true)
        ->assertState('idle');
});

it('runs root entry before initial state entry', function (): void {
    TestMachine::define([
        'id'      => 'order_test',
        'initial' => 'idle',
        'context' => ['log' => []],
        'entry'   => 'logRootEntryAction',
        'states'  => [
            'idle' => [
                'entry' => 'logIdleEntryAction',
                'on'    => ['GO' => 'done'],
            ],
            'done' => ['type' => 'final'],
        ],
    ], behavior: [
        'context' => GenericContext::class,
        'actions' => [
            'logRootEntryAction' => function (ContextManager $context): void {
                $context->set('log', [...$context->get('log'), 'root']);
            },
            'logIdleEntryAction' => function (ContextManager $context): void {
                $context->set('log', [...$context->get('log'), 'idle']);
            },
        ],
    ])
        ->assertContext('log', ['root', 'idle']);
});

// endregion

// region Root exit

it('runs root exit actions when machine reaches final state', function (): void {
    TestMachine::define([
        'id'      => 'root_exit_test',
        'initial' => 'idle',
        'context' => ['root_exited' => false],
        'exit'    => 'markRootExitAction',
        'states'  => [
            'idle' => [
                'on' => ['FINISH' => 'done'],
            ],
            'done' => ['type' => 'final'],
        ],
    ], behavior: [
        'context' => GenericContext::class,
        'actions' => [
            'markRootExitAction' => function (ContextManager $context): void {
                $context->set('root_exited', true);
            },
        ],
    ])
        ->assertContext('root_exited', false)
        ->send('FINISH')
        ->assertState('done')
        ->assertContext('root_exited', true);
});

it('runs root exit before MACHINE_FINISH when initial state is final', function (): void {
    TestMachine::define([
        'id'      => 'immediate_final_test',
        'initial' => 'done',
        'context' => ['root_entered' => false, 'root_exited' => false],
        'entry'   => 'markEntryAction',
        'exit'    => 'markExitAction',
        'states'  => [
            'done' => ['type' => 'final'],
        ],
    ], behavior: [
        'context' => GenericContext::class,
        'actions' => [
            'markEntryAction' => function (ContextManager $context): void {
                $context->set('root_entered', true);
            },
            'markExitAction' => function (ContextManager $context): void {
                $context->set('root_exited', true);
            },
        ],
    ])
        ->assertContext('root_entered', true)
        ->assertContext('root_exited', true);
});

// endregion

// region No root entry/exit

it('works normally without root entry or exit', function (): void {
    TestMachine::define([
        'id'      => 'no_root_actions',
        'initial' => 'idle',
        'context' => ['count' => 0],
        'states'  => [
            'idle' => [
                'on' => ['GO' => 'done'],
            ],
            'done' => ['type' => 'final'],
        ],
    ], ['context' => GenericContext::class])
        ->assertState('idle')
        ->send('GO')
        ->assertState('done');
});

it('does not run root entry on subsequent transitions', function (): void {
    TestMachine::define([
        'id'      => 'once_only_test',
        'initial' => 'a',
        'context' => ['root_entry_count' => 0],
        'entry'   => 'countRootEntryAction',
        'states'  => [
            'a' => ['on' => ['NEXT' => 'b']],
            'b' => ['on' => ['NEXT' => 'c']],
            'c' => ['type' => 'final'],
        ],
    ], behavior: [
        'context' => GenericContext::class,
        'actions' => [
            'countRootEntryAction' => function (ContextManager $context): void {
                $context->set('root_entry_count', $context->get('root_entry_count') + 1);
            },
        ],
    ])
        ->assertContext('root_entry_count', 1)
        ->send('NEXT')
        ->assertContext('root_entry_count', 1)
        ->send('NEXT')
        ->assertContext('root_entry_count', 1);
});

// endregion
