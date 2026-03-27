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
        'context' => ['actionLog' => []],
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
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:parent']);
            },
            'exitParentAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:parent']);
            },
            'exitChildAAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:child_a']);
            },
            'entryChildBAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:child_b']);
            },
        ],
    ])
        // After initialization, parent entry fires (entering the compound state initially)
        // and then we clear the log to isolate the sibling transition
        ->tap(function (TestMachine $testMachine): void {
            $testMachine->context()->set('actionLog', []);
        })
        ->assertState('parent.child_a')
        ->send('SWITCH')
        ->assertState('parent.child_b')
        ->assertContext('actionLog', ['exit:child_a', 'entry:child_b']);
});
