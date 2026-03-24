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

// EventMachine deviation from SCXML: targetless transitions fire exit actions but NOT entry actions.
// In SCXML, internal (targetless) transitions skip both exit and entry. EventMachine runs exit
// unconditionally (see MachineDefinition::transition() line where runExitActions is unguarded)
// but only runs entry protocol when a target state definition exists.
it('targetless transition fires exit actions but not entry actions', function (): void {
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
        // Send TICK: targetless transition fires transition action + exit action, but NOT entry
        ->send('TICK')
        ->assertState('active')
        ->assertContext('action_log', ['entry:active', 'action:tick', 'exit:active']);
});
