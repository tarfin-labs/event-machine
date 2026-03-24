<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Testing\TestMachine;

/**
 * Action ordering verification per SCXML spec (W3C §3.13).
 *
 * The SCXML specification defines the order as: exit (source) → transition → entry (target).
 * EventMachine intentionally uses: transition → exit (source) → entry (target).
 *
 * This test documents and verifies EventMachine's actual ordering guarantee.
 *
 * @see https://www.w3.org/TR/scxml/#SelectingTransitions
 */
it('executes actions in transition → exit → entry order during a state change', function (): void {
    TestMachine::define([
        'id'      => 'action_ordering',
        'initial' => 'state_a',
        'context' => ['action_log' => []],
        'states'  => [
            'state_a' => [
                'exit' => 'exitStateAAction',
                'on'   => [
                    'GO' => [
                        'target'  => 'state_b',
                        'actions' => 'transitionAction',
                    ],
                ],
            ],
            'state_b' => [
                'entry' => 'entryStateBAction',
                'type'  => 'final',
            ],
        ],
    ], behavior: [
        'actions' => [
            'exitStateAAction' => function (ContextManager $context): void {
                $context->set('action_log', [...$context->get('action_log'), 'exit:state_a']);
            },
            'transitionAction' => function (ContextManager $context): void {
                $context->set('action_log', [...$context->get('action_log'), 'transition:state_a->state_b']);
            },
            'entryStateBAction' => function (ContextManager $context): void {
                $context->set('action_log', [...$context->get('action_log'), 'entry:state_b']);
            },
        ],
    ])
        ->assertState('state_a')
        ->assertContext('action_log', [])
        ->send('GO')
        ->assertState('state_b')
        ->assertContext('action_log', [
            // EventMachine ordering: transition → exit → entry
            // SCXML spec ordering would be: exit → transition → entry
            'transition:state_a->state_b',
            'exit:state_a',
            'entry:state_b',
        ]);
});

it('preserves action ordering across a multi-step transition chain', function (): void {
    TestMachine::define([
        'id'      => 'action_ordering_chain',
        'initial' => 'state_a',
        'context' => ['action_log' => []],
        'states'  => [
            'state_a' => [
                'entry' => 'entryStateAAction',
                'exit'  => 'exitStateAAction',
                'on'    => [
                    'GO_B' => [
                        'target'  => 'state_b',
                        'actions' => 'transitionABAction',
                    ],
                ],
            ],
            'state_b' => [
                'entry' => 'entryStateBAction',
                'exit'  => 'exitStateBAction',
                'on'    => [
                    'GO_C' => [
                        'target'  => 'state_c',
                        'actions' => 'transitionBCAction',
                    ],
                ],
            ],
            'state_c' => [
                'entry' => 'entryStateCAction',
                'type'  => 'final',
            ],
        ],
    ], behavior: [
        'actions' => [
            'entryStateAAction' => function (ContextManager $context): void {
                $context->set('action_log', [...$context->get('action_log'), 'entry:state_a']);
            },
            'exitStateAAction' => function (ContextManager $context): void {
                $context->set('action_log', [...$context->get('action_log'), 'exit:state_a']);
            },
            'transitionABAction' => function (ContextManager $context): void {
                $context->set('action_log', [...$context->get('action_log'), 'transition:state_a->state_b']);
            },
            'entryStateBAction' => function (ContextManager $context): void {
                $context->set('action_log', [...$context->get('action_log'), 'entry:state_b']);
            },
            'exitStateBAction' => function (ContextManager $context): void {
                $context->set('action_log', [...$context->get('action_log'), 'exit:state_b']);
            },
            'transitionBCAction' => function (ContextManager $context): void {
                $context->set('action_log', [...$context->get('action_log'), 'transition:state_b->state_c']);
            },
            'entryStateCAction' => function (ContextManager $context): void {
                $context->set('action_log', [...$context->get('action_log'), 'entry:state_c']);
            },
        ],
    ])
        ->assertContext('action_log', ['entry:state_a'])
        ->send('GO_B')
        ->assertContext('action_log', [
            'entry:state_a',
            'transition:state_a->state_b',
            'exit:state_a',
            'entry:state_b',
        ])
        ->send('GO_C')
        ->assertContext('action_log', [
            'entry:state_a',
            'transition:state_a->state_b',
            'exit:state_a',
            'entry:state_b',
            'transition:state_b->state_c',
            'exit:state_b',
            'entry:state_c',
        ]);
});
