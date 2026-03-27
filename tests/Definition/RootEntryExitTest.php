<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Testing\TestMachine;

// region Root entry

it('runs root entry actions on machine initialization', function (): void {
    TestMachine::define([
        'id'      => 'root_entry_test',
        'initial' => 'idle',
        'context' => ['rootEntered' => false],
        'entry'   => 'markRootEntryAction',
        'states'  => [
            'idle' => [
                'on' => ['GO' => 'active'],
            ],
            'active' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'markRootEntryAction' => function (ContextManager $context): void {
                $context->set('rootEntered', true);
            },
        ],
    ])
        ->assertContext('rootEntered', true)
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
        'context' => ['rootExited' => false],
        'exit'    => 'markRootExitAction',
        'states'  => [
            'idle' => [
                'on' => ['FINISH' => 'done'],
            ],
            'done' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'markRootExitAction' => function (ContextManager $context): void {
                $context->set('rootExited', true);
            },
        ],
    ])
        ->assertContext('rootExited', false)
        ->send('FINISH')
        ->assertState('done')
        ->assertContext('rootExited', true);
});

it('runs root exit before MACHINE_FINISH when initial state is final', function (): void {
    TestMachine::define([
        'id'      => 'immediate_final_test',
        'initial' => 'done',
        'context' => ['rootEntered' => false, 'rootExited' => false],
        'entry'   => 'markEntryAction',
        'exit'    => 'markExitAction',
        'states'  => [
            'done' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'markEntryAction' => function (ContextManager $context): void {
                $context->set('rootEntered', true);
            },
            'markExitAction' => function (ContextManager $context): void {
                $context->set('rootExited', true);
            },
        ],
    ])
        ->assertContext('rootEntered', true)
        ->assertContext('rootExited', true);
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
    ])
        ->assertState('idle')
        ->send('GO')
        ->assertState('done');
});

it('does not run root entry on subsequent transitions', function (): void {
    TestMachine::define([
        'id'      => 'once_only_test',
        'initial' => 'a',
        'context' => ['rootEntryCount' => 0],
        'entry'   => 'countRootEntryAction',
        'states'  => [
            'a' => ['on' => ['NEXT' => 'b']],
            'b' => ['on' => ['NEXT' => 'c']],
            'c' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'countRootEntryAction' => function (ContextManager $context): void {
                $context->set('rootEntryCount', $context->get('rootEntryCount') + 1);
            },
        ],
    ])
        ->assertContext('rootEntryCount', 1)
        ->send('NEXT')
        ->assertContext('rootEntryCount', 1)
        ->send('NEXT')
        ->assertContext('rootEntryCount', 1);
});

// endregion
