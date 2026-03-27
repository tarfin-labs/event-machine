<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Testing\TestMachine;

// === External Self-Transition (explicit target = same state) ===

it('external self-transition triggers both exit and entry actions', function (): void {
    TestMachine::define([
        'id'      => 'self_transition',
        'initial' => 'active',
        'context' => ['actionLog' => []],
        'states'  => [
            'active' => [
                'entry' => 'appendEntryAction',
                'exit'  => 'appendExitAction',
                'on'    => [
                    'REFRESH' => 'active',
                ],
            ],
        ],
    ], behavior: [
        'actions' => [
            'appendEntryAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:active']);
            },
            'appendExitAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:active']);
            },
        ],
    ])
        // Initial entry fires the entry action
        ->assertState('active')
        ->assertContext('actionLog', ['entry:active'])
        // Send REFRESH: external self-transition should fire exit then entry
        ->send('REFRESH')
        ->assertState('active')
        ->assertContext('actionLog', ['entry:active', 'exit:active', 'entry:active']);
});

// === Targetless (Internal) Transition ===

// SCXML-correct: targetless transitions skip BOTH exit and entry actions.
// Only the transition action itself runs.
it('targetless transition skips both exit and entry actions (SCXML semantics)', function (): void {
    TestMachine::define([
        'id'      => 'internal_transition',
        'initial' => 'active',
        'context' => ['actionLog' => []],
        'states'  => [
            'active' => [
                'entry' => 'appendEntryAction',
                'exit'  => 'appendExitAction',
                'on'    => [
                    'TICK' => [
                        'actions' => 'appendTickAction',
                    ],
                ],
            ],
        ],
    ], behavior: [
        'actions' => [
            'appendEntryAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'entry:active']);
            },
            'appendExitAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'exit:active']);
            },
            'appendTickAction' => function (ContextManager $context): void {
                $context->set('actionLog', [...$context->get('actionLog'), 'action:tick']);
            },
        ],
    ])
        // Initial entry fires the entry action
        ->assertState('active')
        ->assertContext('actionLog', ['entry:active'])
        // Send TICK: targetless transition fires ONLY transition action — no exit, no entry
        ->send('TICK')
        ->assertState('active')
        ->assertContext('actionLog', ['entry:active', 'action:tick']);
});
