<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

// ═══════════════════════════════════════════════════════════════
//  3-Region Parallel State
//
//  Verifies @done only fires when ALL 3 regions reach final.
//  Verifies event broadcasting to all 3 regions.
// ═══════════════════════════════════════════════════════════════

it('three regions all enter initial states on parallel entry', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'three_region',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 'completed',
                'states' => [
                    'payment' => [
                        'initial' => 'pending',
                        'states'  => [
                            'pending' => ['on' => ['PAY' => 'paid']],
                            'paid'    => ['type' => 'final'],
                        ],
                    ],
                    'shipping' => [
                        'initial' => 'preparing',
                        'states'  => [
                            'preparing' => ['on' => ['SHIP' => 'shipped']],
                            'shipped'   => ['type' => 'final'],
                        ],
                    ],
                    'documents' => [
                        'initial' => 'drafting',
                        'states'  => [
                            'drafting'  => ['on' => ['FINALIZE_DOCS' => 'finalized']],
                            'finalized' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    expect($state->value)->toHaveCount(3);
    expect($state->matches('processing.payment.pending'))->toBeTrue();
    expect($state->matches('processing.shipping.preparing'))->toBeTrue();
    expect($state->matches('processing.documents.drafting'))->toBeTrue();
});

it('onDone does NOT fire when only 1 of 3 regions is final', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'three_region',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 'completed',
                'states' => [
                    'payment' => [
                        'initial' => 'pending',
                        'states'  => [
                            'pending' => ['on' => ['PAY' => 'paid']],
                            'paid'    => ['type' => 'final'],
                        ],
                    ],
                    'shipping' => [
                        'initial' => 'preparing',
                        'states'  => [
                            'preparing' => ['on' => ['SHIP' => 'shipped']],
                            'shipped'   => ['type' => 'final'],
                        ],
                    ],
                    'documents' => [
                        'initial' => 'drafting',
                        'states'  => [
                            'drafting'  => ['on' => ['FINALIZE_DOCS' => 'finalized']],
                            'finalized' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'PAY'], $state);

    expect($state->matches('processing.payment.paid'))->toBeTrue();
    expect($state->matches('processing.shipping.preparing'))->toBeTrue();
    expect($state->matches('processing.documents.drafting'))->toBeTrue();
    expect($state->matches('completed'))->toBeFalse();
});

it('onDone does NOT fire when only 2 of 3 regions are final', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'three_region',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 'completed',
                'states' => [
                    'payment' => [
                        'initial' => 'pending',
                        'states'  => [
                            'pending' => ['on' => ['PAY' => 'paid']],
                            'paid'    => ['type' => 'final'],
                        ],
                    ],
                    'shipping' => [
                        'initial' => 'preparing',
                        'states'  => [
                            'preparing' => ['on' => ['SHIP' => 'shipped']],
                            'shipped'   => ['type' => 'final'],
                        ],
                    ],
                    'documents' => [
                        'initial' => 'drafting',
                        'states'  => [
                            'drafting'  => ['on' => ['FINALIZE_DOCS' => 'finalized']],
                            'finalized' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'PAY'], $state);
    $state = $definition->transition(['type' => 'SHIP'], $state);

    expect($state->matches('processing.payment.paid'))->toBeTrue();
    expect($state->matches('processing.shipping.shipped'))->toBeTrue();
    expect($state->matches('processing.documents.drafting'))->toBeTrue();
    expect($state->matches('completed'))->toBeFalse();
});

it('onDone fires only when all 3 regions reach final', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'three_region',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 'completed',
                'states' => [
                    'payment' => [
                        'initial' => 'pending',
                        'states'  => [
                            'pending' => ['on' => ['PAY' => 'paid']],
                            'paid'    => ['type' => 'final'],
                        ],
                    ],
                    'shipping' => [
                        'initial' => 'preparing',
                        'states'  => [
                            'preparing' => ['on' => ['SHIP' => 'shipped']],
                            'shipped'   => ['type' => 'final'],
                        ],
                    ],
                    'documents' => [
                        'initial' => 'drafting',
                        'states'  => [
                            'drafting'  => ['on' => ['FINALIZE_DOCS' => 'finalized']],
                            'finalized' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'PAY'], $state);
    $state = $definition->transition(['type' => 'SHIP'], $state);
    $state = $definition->transition(['type' => 'FINALIZE_DOCS'], $state);

    expect($state->matches('completed'))->toBeTrue();
});

it('3 regions complete in any order and onDone fires correctly', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'three_region_order',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 'completed',
                'states' => [
                    'alpha' => [
                        'initial' => 'waiting',
                        'states'  => [
                            'waiting'  => ['on' => ['DONE_ALPHA' => 'finished']],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                    'beta' => [
                        'initial' => 'waiting',
                        'states'  => [
                            'waiting'  => ['on' => ['DONE_BETA' => 'finished']],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                    'gamma' => [
                        'initial' => 'waiting',
                        'states'  => [
                            'waiting'  => ['on' => ['DONE_GAMMA' => 'finished']],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    // Complete in reverse order: gamma, alpha, beta
    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'DONE_GAMMA'], $state);

    expect($state->matches('completed'))->toBeFalse();

    $state = $definition->transition(['type' => 'DONE_ALPHA'], $state);

    expect($state->matches('completed'))->toBeFalse();

    $state = $definition->transition(['type' => 'DONE_BETA'], $state);

    expect($state->matches('completed'))->toBeTrue();
});

it('broadcasts a shared event to all 3 regions that handle it', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'three_region_broadcast',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 'completed',
                'states' => [
                    'region_a' => [
                        'initial' => 'waiting',
                        'states'  => [
                            'waiting'   => ['on' => ['CANCEL_ALL' => 'cancelled']],
                            'cancelled' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'waiting',
                        'states'  => [
                            'waiting'   => ['on' => ['CANCEL_ALL' => 'cancelled']],
                            'cancelled' => ['type' => 'final'],
                        ],
                    ],
                    'region_c' => [
                        'initial' => 'waiting',
                        'states'  => [
                            'waiting'   => ['on' => ['CANCEL_ALL' => 'cancelled']],
                            'cancelled' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    // CANCEL_ALL handled by all 3 regions — all should transition
    $state = $definition->transition(['type' => 'CANCEL_ALL'], $state);

    // All three final → @done fires
    expect($state->matches('completed'))->toBeTrue();
});

it('event only affects regions that handle it, not others', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'three_region_partial',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 'completed',
                'states' => [
                    'region_a' => [
                        'initial' => 'idle',
                        'states'  => [
                            'idle' => ['on' => ['EVENT_A' => 'done']],
                            'done' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'idle',
                        'states'  => [
                            'idle' => ['on' => ['EVENT_B' => 'done']],
                            'done' => ['type' => 'final'],
                        ],
                    ],
                    'region_c' => [
                        'initial' => 'idle',
                        'states'  => [
                            'idle' => ['on' => ['EVENT_C' => 'done']],
                            'done' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    // EVENT_A only affects region_a
    $state = $definition->transition(['type' => 'EVENT_A'], $state);

    expect($state->matches('processing.region_a.done'))->toBeTrue();
    expect($state->matches('processing.region_b.idle'))->toBeTrue();
    expect($state->matches('processing.region_c.idle'))->toBeTrue();
    expect($state->matches('completed'))->toBeFalse();
});

it('3-region parallel with entry actions and context updates per region', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'three_region_context',
            'initial' => 'processing',
            'context' => [
                'regionADone' => false,
                'regionBDone' => false,
                'regionCDone' => false,
            ],
            'states' => [
                'processing' => [
                    'type'   => 'parallel',
                    '@done'  => 'completed',
                    'states' => [
                        'region_a' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'on' => [
                                        'FINISH_A' => [
                                            'target'  => 'done',
                                            'actions' => 'markRegionADoneAction',
                                        ],
                                    ],
                                ],
                                'done' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'on' => [
                                        'FINISH_B' => [
                                            'target'  => 'done',
                                            'actions' => 'markRegionBDoneAction',
                                        ],
                                    ],
                                ],
                                'done' => ['type' => 'final'],
                            ],
                        ],
                        'region_c' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'on' => [
                                        'FINISH_C' => [
                                            'target'  => 'done',
                                            'actions' => 'markRegionCDoneAction',
                                        ],
                                    ],
                                ],
                                'done' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'markRegionADoneAction' => fn (ContextManager $ctx) => $ctx->set('regionADone', true),
                'markRegionBDoneAction' => fn (ContextManager $ctx) => $ctx->set('regionBDone', true),
                'markRegionCDoneAction' => fn (ContextManager $ctx) => $ctx->set('regionCDone', true),
            ],
        ]
    );

    $state = $definition->getInitialState();

    $state = $definition->transition(['type' => 'FINISH_B'], $state);
    expect($state->context->get('regionBDone'))->toBeTrue();
    expect($state->context->get('regionADone'))->toBeFalse();
    expect($state->context->get('regionCDone'))->toBeFalse();

    $state = $definition->transition(['type' => 'FINISH_C'], $state);
    expect($state->context->get('regionCDone'))->toBeTrue();

    $state = $definition->transition(['type' => 'FINISH_A'], $state);
    expect($state->context->get('regionADone'))->toBeTrue();

    // All 3 done → @done fires
    expect($state->matches('completed'))->toBeTrue();
});

it('3-region parallel with multi-step regions completes correctly', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'three_region_multistep',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 'completed',
                'states' => [
                    'docs' => [
                        'initial' => 'drafting',
                        'states'  => [
                            'drafting'  => ['on' => ['SUBMIT_DOCS' => 'reviewing']],
                            'reviewing' => ['on' => ['APPROVE_DOCS' => 'approved']],
                            'approved'  => ['type' => 'final'],
                        ],
                    ],
                    'payment' => [
                        'initial' => 'invoicing',
                        'states'  => [
                            'invoicing'        => ['on' => ['SEND_INVOICE' => 'awaiting_payment']],
                            'awaiting_payment' => ['on' => ['RECEIVE_PAYMENT' => 'paid']],
                            'paid'             => ['type' => 'final'],
                        ],
                    ],
                    'logistics' => [
                        'initial' => 'picking',
                        'states'  => [
                            'picking'    => ['on' => ['PACK' => 'packing']],
                            'packing'    => ['on' => ['DISPATCH' => 'dispatched']],
                            'dispatched' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    // Interleave steps across regions
    $state = $definition->transition(['type' => 'SUBMIT_DOCS'], $state);
    $state = $definition->transition(['type' => 'SEND_INVOICE'], $state);
    $state = $definition->transition(['type' => 'PACK'], $state);

    expect($state->matches('processing.docs.reviewing'))->toBeTrue();
    expect($state->matches('processing.payment.awaiting_payment'))->toBeTrue();
    expect($state->matches('processing.logistics.packing'))->toBeTrue();
    expect($state->matches('completed'))->toBeFalse();

    $state = $definition->transition(['type' => 'APPROVE_DOCS'], $state);
    $state = $definition->transition(['type' => 'DISPATCH'], $state);
    $state = $definition->transition(['type' => 'RECEIVE_PAYMENT'], $state);

    expect($state->matches('completed'))->toBeTrue();
});
