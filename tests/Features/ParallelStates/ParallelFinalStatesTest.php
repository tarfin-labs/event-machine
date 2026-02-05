<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

test('parallel state detects when all regions are final', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'test',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                'states' => [
                    'regionA' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => [
                                'on' => [
                                    'COMPLETE_A' => 'done',
                                ],
                            ],
                            'done' => ['type' => 'final'],
                        ],
                    ],
                    'regionB' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => [
                                'on' => [
                                    'COMPLETE_B' => 'done',
                                ],
                            ],
                            'done' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $state = $definition->getInitialState();

    // Initially neither region is final
    expect($state->matches('processing.regionA.working'))->toBeTrue();
    expect($state->matches('processing.regionB.working'))->toBeTrue();

    // Complete region A
    $state = $definition->transition(['type' => 'COMPLETE_A'], $state);
    expect($state->matches('processing.regionA.done'))->toBeTrue();
    expect($state->matches('processing.regionB.working'))->toBeTrue();

    // Complete region B
    $state = $definition->transition(['type' => 'COMPLETE_B'], $state);
    expect($state->matches('processing.regionA.done'))->toBeTrue();
    expect($state->matches('processing.regionB.done'))->toBeTrue();
});

test('regions can complete in any order', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'test',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                'states' => [
                    'documents' => [
                        'initial' => 'pending',
                        'states'  => [
                            'pending' => [
                                'on' => [
                                    'DOCS_READY' => 'complete',
                                ],
                            ],
                            'complete' => ['type' => 'final'],
                        ],
                    ],
                    'payment' => [
                        'initial' => 'pending',
                        'states'  => [
                            'pending' => [
                                'on' => [
                                    'PAYMENT_RECEIVED' => 'complete',
                                ],
                            ],
                            'complete' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $state = $definition->getInitialState();

    // Complete payment first (opposite order)
    $state = $definition->transition(['type' => 'PAYMENT_RECEIVED'], $state);
    expect($state->matches('processing.documents.pending'))->toBeTrue();
    expect($state->matches('processing.payment.complete'))->toBeTrue();

    // Then complete documents
    $state = $definition->transition(['type' => 'DOCS_READY'], $state);
    expect($state->matches('processing.documents.complete'))->toBeTrue();
    expect($state->matches('processing.payment.complete'))->toBeTrue();
});

test('three regions workflow completes correctly', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'orderWorkflow',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                'states' => [
                    'documents' => [
                        'initial' => 'pending',
                        'states'  => [
                            'pending' => [
                                'on' => [
                                    'UPLOAD_DOCS' => 'reviewing',
                                ],
                            ],
                            'reviewing' => [
                                'on' => [
                                    'APPROVE_DOCS' => 'complete',
                                ],
                            ],
                            'complete' => ['type' => 'final'],
                        ],
                    ],
                    'delivery' => [
                        'initial' => 'preparing',
                        'states'  => [
                            'preparing' => [
                                'on' => [
                                    'SHIP' => 'shipped',
                                ],
                            ],
                            'shipped' => [
                                'on' => [
                                    'DELIVER' => 'delivered',
                                ],
                            ],
                            'delivered' => ['type' => 'final'],
                        ],
                    ],
                    'invoice' => [
                        'initial' => 'draft',
                        'states'  => [
                            'draft' => [
                                'on' => [
                                    'SEND_INVOICE' => 'sent',
                                ],
                            ],
                            'sent' => [
                                'on' => [
                                    'RECEIVE_PAYMENT' => 'paid',
                                ],
                            ],
                            'paid' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $state = $definition->getInitialState();

    // Process each region step by step
    $state = $definition->transition(['type' => 'UPLOAD_DOCS'], $state);
    expect($state->matches('processing.documents.reviewing'))->toBeTrue();

    $state = $definition->transition(['type' => 'SHIP'], $state);
    expect($state->matches('processing.delivery.shipped'))->toBeTrue();

    $state = $definition->transition(['type' => 'SEND_INVOICE'], $state);
    expect($state->matches('processing.invoice.sent'))->toBeTrue();

    $state = $definition->transition(['type' => 'APPROVE_DOCS'], $state);
    expect($state->matches('processing.documents.complete'))->toBeTrue();

    $state = $definition->transition(['type' => 'DELIVER'], $state);
    expect($state->matches('processing.delivery.delivered'))->toBeTrue();

    $state = $definition->transition(['type' => 'RECEIVE_PAYMENT'], $state);
    expect($state->matches('processing.invoice.paid'))->toBeTrue();

    // All three regions should be in final states
    $documentsState = $definition->idMap[$state->value[0] ?? ''];
    $deliveryState  = $definition->idMap[$state->value[1] ?? ''];
    $invoiceState   = $definition->idMap[$state->value[2] ?? ''];

    expect($documentsState->type)->toBe(StateDefinitionType::FINAL);
    expect($deliveryState->type)->toBe(StateDefinitionType::FINAL);
    expect($invoiceState->type)->toBe(StateDefinitionType::FINAL);
});
