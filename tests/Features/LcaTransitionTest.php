<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Testing\TestMachine;

// === LCA (Lowest Common Ancestor) Transition Tests ===
// When transitioning between sibling states, the LCA is their shared parent.
// Per statechart semantics, the parent should NOT be exited/re-entered for
// sibling transitions — only the source and target states should fire exit/entry.

it('does not exit or enter parent when transitioning between sibling states', function (): void {
    TestMachine::define([
        'id'      => 'lca_sibling_test',
        'initial' => 'parent.child_a',
        'context' => ['action_log' => []],
        'states'  => [
            'parent' => [
                'initial' => 'child_a',
                'entry'   => 'entryParentAction',
                'exit'    => 'exitParentAction',
                'states'  => [
                    'child_a' => [
                        'exit' => 'exitChildAAction',
                        'on'   => [
                            'SWITCH' => 'parent.child_b',
                        ],
                    ],
                    'child_b' => [
                        'entry' => 'entryChildBAction',
                    ],
                ],
            ],
        ],
    ], behavior: [
        'actions' => [
            'entryParentAction' => function (ContextManager $context): void {
                $context->set('action_log', [...$context->get('action_log'), 'entry:parent']);
            },
            'exitParentAction' => function (ContextManager $context): void {
                $context->set('action_log', [...$context->get('action_log'), 'exit:parent']);
            },
            'exitChildAAction' => function (ContextManager $context): void {
                $context->set('action_log', [...$context->get('action_log'), 'exit:child_a']);
            },
            'entryChildBAction' => function (ContextManager $context): void {
                $context->set('action_log', [...$context->get('action_log'), 'entry:child_b']);
            },
        ],
    ])
        // After initialization, parent entry fires (entering the compound state initially)
        // and then we clear the log to isolate the sibling transition
        ->tap(function (TestMachine $testMachine): void {
            $testMachine->context()->set('action_log', []);
        })
        ->assertState('parent.child_a')
        ->send('SWITCH')
        ->assertState('parent.child_b')
        ->assertContext('action_log', ['exit:child_a', 'entry:child_b']);
});
