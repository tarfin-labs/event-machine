<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Testing\TestMachine;

// === External Self-Transition (explicit target = same state) ===

it('external self-transition triggers both exit and entry actions', function (): void {
    TestMachine::define([
        'id'      => 'self_transition',
        'initial' => 'active',
        'context' => ['action_log' => []],
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
                $context->set('action_log', [...$context->get('action_log'), 'entry:active']);
            },
            'appendExitAction' => function (ContextManager $context): void {
                $context->set('action_log', [...$context->get('action_log'), 'exit:active']);
            },
        ],
    ])
        // Initial entry fires the entry action
        ->assertState('active')
        ->assertContext('action_log', ['entry:active'])
        // Send REFRESH: external self-transition should fire exit then entry
        ->send('REFRESH')
        ->assertState('active')
        ->assertContext('action_log', ['entry:active', 'exit:active', 'entry:active']);
});

// === Targetless (Internal) Transition ===

// SCXML-correct: targetless transitions fire exit actions (SCXML order: exit before transition)
// but NOT entry actions. Exit runs before transition action per SCXML section 3.13.
it('targetless transition fires exit actions before transition action but not entry actions', function (): void {
    TestMachine::define([
        'id'      => 'internal_transition',
        'initial' => 'active',
        'context' => ['action_log' => []],
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
                $context->set('action_log', [...$context->get('action_log'), 'entry:active']);
            },
            'appendExitAction' => function (ContextManager $context): void {
                $context->set('action_log', [...$context->get('action_log'), 'exit:active']);
            },
            'appendTickAction' => function (ContextManager $context): void {
                $context->set('action_log', [...$context->get('action_log'), 'action:tick']);
            },
        ],
    ])
        // Initial entry fires the entry action
        ->assertState('active')
        ->assertContext('action_log', ['entry:active'])
        // Send TICK: targetless transition fires exit before transition action (SCXML order), no entry
        ->send('TICK')
        ->assertState('active')
        ->assertContext('action_log', ['entry:active', 'exit:active', 'action:tick']);
});
