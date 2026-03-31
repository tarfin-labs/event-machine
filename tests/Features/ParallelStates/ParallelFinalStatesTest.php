<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
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
                    'region_a' => [
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
                    'region_b' => [
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
    expect($state->matches('processing.region_a.working'))->toBeTrue();
    expect($state->matches('processing.region_b.working'))->toBeTrue();

    // Complete region A
    $state = $definition->transition(['type' => 'COMPLETE_A'], $state);
    expect($state->matches('processing.region_a.done'))->toBeTrue();
    expect($state->matches('processing.region_b.working'))->toBeTrue();

    // Complete region B
    $state = $definition->transition(['type' => 'COMPLETE_B'], $state);
    expect($state->matches('processing.region_a.done'))->toBeTrue();
    expect($state->matches('processing.region_b.done'))->toBeTrue();
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
        'id'      => 'order_workflow',
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

test('onDone transitions to next state when all regions are final', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'workflow',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 'completed',
                'states' => [
                    'taskA' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => [
                                'on' => [
                                    'FINISH_A' => 'done',
                                ],
                            ],
                            'done' => ['type' => 'final'],
                        ],
                    ],
                    'taskB' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => [
                                'on' => [
                                    'FINISH_B' => 'done',
                                ],
                            ],
                            'done' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    // Initially in parallel processing state
    expect($state->matches('processing.taskA.working'))->toBeTrue();
    expect($state->matches('processing.taskB.working'))->toBeTrue();

    // Complete task A - still in processing
    $state = $definition->transition(['type' => 'FINISH_A'], $state);
    expect($state->matches('processing.taskA.done'))->toBeTrue();
    expect($state->matches('processing.taskB.working'))->toBeTrue();

    // Complete task B - should automatically transition to 'completed' via onDone
    $state = $definition->transition(['type' => 'FINISH_B'], $state);
    expect($state->matches('completed'))->toBeTrue();
    expect($state->currentStateDefinition->type)->toBe(StateDefinitionType::FINAL);
});

test('onDone works with array configuration', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'workflow',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'  => 'parallel',
                '@done' => [
                    'target' => 'finished',
                ],
                'states' => [
                    'region_a' => [
                        'initial' => 'active',
                        'states'  => [
                            'active' => [
                                'on' => [
                                    'DONE_A' => 'complete',
                                ],
                            ],
                            'complete' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'active',
                        'states'  => [
                            'active' => [
                                'on' => [
                                    'DONE_B' => 'complete',
                                ],
                            ],
                            'complete' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'finished' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    // Complete both regions
    $state = $definition->transition(['type' => 'DONE_A'], $state);
    $state = $definition->transition(['type' => 'DONE_B'], $state);

    // Should transition to finished state
    expect($state->matches('finished'))->toBeTrue();
});

test('nested final state in compound sub-state should NOT trigger region completion', function (): void {
    // Bug scenario: customer region has a compound sub-state (findeks) with its own final state.
    // When findeks reaches its final state (report_saved), areAllRegionsFinal() should NOT
    // consider the customer region as "final" — customer.completed is the real final state.
    $definition = MachineDefinition::define([
        'id'      => 'car_sales',
        'initial' => 'data_collection',
        'states'  => [
            'data_collection' => [
                'type'   => 'parallel',
                '@done'  => 'application_submitted',
                'states' => [
                    'retailer' => [
                        'initial' => 'awaiting_vehicle_info',
                        'states'  => [
                            'awaiting_vehicle_info' => [
                                'on' => [
                                    'VEHICLE_INFO_SUBMITTED' => 'completed',
                                ],
                            ],
                            'completed' => ['type' => 'final'],
                        ],
                    ],
                    'customer' => [
                        'initial' => 'awaiting_consent',
                        'states'  => [
                            'awaiting_consent' => [
                                'on' => [
                                    'CONSENT_GRANTED' => 'verification',
                                ],
                            ],
                            'verification' => [
                                'initial' => 'checking',
                                'states'  => [
                                    'checking' => [
                                        'on' => [
                                            'REPORT_SAVED' => 'report_received',
                                        ],
                                    ],
                                    'report_received' => ['type' => 'final'],
                                ],
                            ],
                            'under_review' => [
                                'on' => [
                                    'REVIEW_COMPLETED' => 'completed',
                                ],
                            ],
                            'completed' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'application_submitted' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    // Retailer completes immediately
    $state = $definition->transition(['type' => 'VEHICLE_INFO_SUBMITTED'], $state);
    expect($state->matches('data_collection.retailer.completed'))->toBeTrue();

    // Customer enters verification compound state
    $state = $definition->transition(['type' => 'CONSENT_GRANTED'], $state);
    expect($state->matches('data_collection.customer.verification.checking'))->toBeTrue();

    // Verification sub-state reaches its final state (report_received)
    $state = $definition->transition(['type' => 'REPORT_SAVED'], $state);

    // The machine should NOT have transitioned to application_submitted!
    // customer region is at verification.report_received (nested final), NOT at customer.completed.
    // areAllRegionsFinal() must only consider DIRECT children of the parallel region as "final",
    // not deeply nested final states within compound sub-states.
    expect($state->matches('application_submitted'))->toBeFalse(
        'areAllRegionsFinal() incorrectly detected nested final state as region completion'
    );
    expect($state->matches('data_collection.customer.verification.report_received'))->toBeTrue();
    expect($state->matches('data_collection.retailer.completed'))->toBeTrue();
});

test('car sales style: compound onDone + cross-region sync + parallel onDone', function (): void {
    // Full car sales scenario:
    // - retailer region has @always guard that waits for customer's policy check
    // - customer region has nested compound state (verification) with onDone
    // - both regions must reach completed (final) for parallel onDone to fire
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'car_sales',
            'initial' => 'data_collection',
            'context' => ['policyData' => null],
            'states'  => [
                'data_collection' => [
                    'type'   => 'parallel',
                    '@done'  => 'application_submitted',
                    'states' => [
                        'retailer' => [
                            'initial' => 'pricing',
                            'states'  => [
                                'pricing' => [
                                    'on' => [
                                        'PRICING_COMPLETED' => 'awaiting_pre_approval',
                                    ],
                                ],
                                'awaiting_pre_approval' => [
                                    'on' => [
                                        '@always' => [
                                            ['target' => 'awaiting_payment_options', 'guards' => 'isPreApprovalPassedGuard'],
                                        ],
                                    ],
                                ],
                                'awaiting_payment_options' => [
                                    'on' => [
                                        'PAYMENT_OPTIONS_SELECTED' => 'completed',
                                    ],
                                ],
                                'completed' => ['type' => 'final'],
                            ],
                        ],
                        'customer' => [
                            'initial' => 'awaiting_consent',
                            'states'  => [
                                'awaiting_consent' => [
                                    'on' => [
                                        'CONSENT_GRANTED' => 'verification',
                                    ],
                                ],
                                'verification' => [
                                    'initial' => 'checking',
                                    '@done'   => 'under_policy_review',
                                    'states'  => [
                                        'checking' => [
                                            'on' => [
                                                'REPORT_SAVED' => 'report_received',
                                            ],
                                        ],
                                        'report_received' => ['type' => 'final'],
                                    ],
                                ],
                                'under_policy_review' => [
                                    'on' => [
                                        'POLICY_APPROVED' => [
                                            'target'  => 'completed',
                                            'actions' => 'setPolicyApprovedAction',
                                        ],
                                    ],
                                ],
                                'completed' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'application_submitted' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'guards' => [
                'isPreApprovalPassedGuard' => fn (ContextManager $ctx, EventBehavior $event, State $state) => $ctx->get('policyData') === 'approved',
            ],
            'actions' => [
                'setPolicyApprovedAction' => fn (ContextManager $ctx) => $ctx->set('policyData', 'approved'),
            ],
        ]
    );

    $state = $definition->getInitialState();
    expect($state->matches('data_collection.retailer.pricing'))->toBeTrue();
    expect($state->matches('data_collection.customer.awaiting_consent'))->toBeTrue();

    // Step 1: Retailer completes pricing → enters awaiting_pre_approval
    $state = $definition->transition(['type' => 'PRICING_COMPLETED'], $state);
    expect($state->matches('data_collection.retailer.awaiting_pre_approval'))->toBeTrue();

    // Step 2: Customer grants consent → enters verification.checking
    $state = $definition->transition(['type' => 'CONSENT_GRANTED'], $state);
    expect($state->matches('data_collection.customer.verification.checking'))->toBeTrue();

    // Step 3: Verification report saved → compound onDone fires → under_policy_review
    $state = $definition->transition(['type' => 'REPORT_SAVED'], $state);
    expect($state->matches('data_collection.customer.under_policy_review'))->toBeTrue(
        'Compound onDone should transition from verification to under_policy_review'
    );
    // Retailer should still be waiting (policy not approved yet)
    expect($state->matches('data_collection.retailer.awaiting_pre_approval'))->toBeTrue();

    // Step 4: Policy approved → customer completed + retailer @always fires
    $state = $definition->transition(['type' => 'POLICY_APPROVED'], $state);
    expect($state->matches('data_collection.customer.completed'))->toBeTrue();
    // Cross-region sync: retailer's @always guard should now pass
    expect($state->matches('data_collection.retailer.awaiting_payment_options'))->toBeTrue(
        'Cross-region @always should fire when policy_result is set'
    );

    // Step 5: Retailer selects payment options → both regions completed → parallel onDone
    $state = $definition->transition(['type' => 'PAYMENT_OPTIONS_SELECTED'], $state);
    expect($state->matches('application_submitted'))->toBeTrue(
        'Parallel onDone should fire when both regions reach their final state'
    );
});

test('three levels of nesting: deeply nested final should NOT trigger region completion', function (): void {
    // region > compound > inner_compound > final
    // Only a DIRECT child final of the region should count.
    $definition = MachineDefinition::define([
        'id'      => 'deep_nesting',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 'all_done',
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => [
                                'on' => ['FINISH_A' => 'completed'],
                            ],
                            'completed' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'outer',
                        'states'  => [
                            'outer' => [
                                'initial' => 'inner',
                                'states'  => [
                                    'inner' => [
                                        'initial' => 'running',
                                        'states'  => [
                                            'running' => [
                                                'on' => ['DEEP_DONE' => 'deep_final'],
                                            ],
                                            'deep_final' => ['type' => 'final'],
                                        ],
                                    ],
                                ],
                            ],
                            'completed' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'all_done' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    // Complete region A
    $state = $definition->transition(['type' => 'FINISH_A'], $state);
    expect($state->matches('processing.region_a.completed'))->toBeTrue();

    // Trigger deeply nested final (3 levels deep)
    $state = $definition->transition(['type' => 'DEEP_DONE'], $state);

    // The parallel onDone should NOT fire — deep_final is 3 levels below region_b
    expect($state->matches('all_done'))->toBeFalse(
        'areAllRegionsFinal() should not count a 3-level-deep final state as region completion'
    );
    expect($state->matches('processing.region_b.outer.inner.deep_final'))->toBeTrue();
});

test('non-onDone compound parent should NOT propagate to grandparent onDone', function (): void {
    // When a final state is inside a compound without onDone, the walk-up should STOP.
    // It must NOT skip to a grandparent compound that has onDone.
    //
    // region > outer(compound, onDone: after_outer) > inner(compound, NO onDone) > final
    //
    // XState semantics: inner is "done" but has no handler. outer should NOT fire.
    $definition = MachineDefinition::define([
        'id'      => 'propagation_test',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 'finished',
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => [
                                'on' => ['FINISH_A' => 'completed'],
                            ],
                            'completed' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'outer',
                        'states'  => [
                            'outer' => [
                                'initial' => 'inner',
                                '@done'   => 'after_outer',
                                'states'  => [
                                    'inner' => [
                                        'initial' => 'running',
                                        // NO onDone here — inner is a compound without onDone
                                        'states' => [
                                            'running' => [
                                                'on' => ['INNER_DONE' => 'inner_final'],
                                            ],
                                            'inner_final' => ['type' => 'final'],
                                        ],
                                    ],
                                ],
                            ],
                            'after_outer' => [
                                'on' => ['CONTINUE' => 'completed'],
                            ],
                            'completed' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'finished' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();
    expect($state->matches('processing.region_b.outer.inner.running'))->toBeTrue();

    // Trigger inner's final state
    $state = $definition->transition(['type' => 'INNER_DONE'], $state);

    // inner is "done" but has no onDone → should stay at inner.inner_final
    // outer.onDone should NOT fire because inner (the active child of outer) is NOT a final-type state
    expect($state->matches('processing.region_b.outer.inner.inner_final'))->toBeTrue(
        'Should stay at inner.inner_final since inner has no onDone'
    );
    expect($state->matches('processing.region_b.after_outer'))->toBeFalse(
        'outer.onDone should NOT fire — only the immediate compound parent checks onDone'
    );
});

test('compound parent exit actions fire when onDone transitions', function (): void {
    // When compound onDone fires, both the final child AND the compound parent
    // should run their exit actions (XState lifecycle).
    $exitLog = [];

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'exit_actions_test',
            'initial' => 'processing',
            'states'  => [
                'processing' => [
                    'type'   => 'parallel',
                    '@done'  => 'done',
                    'states' => [
                        'region_a' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'on' => ['FINISH_A' => 'completed'],
                                ],
                                'completed' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'sub_process',
                            'states'  => [
                                'sub_process' => [
                                    'initial' => 'checking',
                                    '@done'   => 'after_sub',
                                    'exit'    => 'logSubProcessExitAction',
                                    'states'  => [
                                        'checking' => [
                                            'on' => ['CHECK_DONE' => 'verified'],
                                        ],
                                        'verified' => [
                                            'type' => 'final',
                                            'exit' => 'logVerifiedExitAction',
                                        ],
                                    ],
                                ],
                                'after_sub' => [
                                    'on' => ['FINISH_B' => 'completed'],
                                ],
                                'completed' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'logVerifiedExitAction' => function () use (&$exitLog): void {
                    $exitLog[] = 'verified_exit';
                },
                'logSubProcessExitAction' => function () use (&$exitLog): void {
                    $exitLog[] = 'sub_process_exit';
                },
            ],
        ]
    );

    $state = $definition->getInitialState();

    // Trigger compound onDone
    $state = $definition->transition(['type' => 'CHECK_DONE'], $state);
    expect($state->matches('processing.region_b.after_sub'))->toBeTrue();

    // Both exit actions should have fired: final state + compound parent
    expect($exitLog)->toContain('verified_exit');
    expect($exitLog)->toContain('sub_process_exit');
});

test('compound onDone with actions config runs onDone actions', function (): void {
    // onDone: { target: 'next', actions: 'myAction' } should run the actions
    $actionLog = [];

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'oncompletedctions_test',
            'initial' => 'processing',
            'states'  => [
                'processing' => [
                    'type'   => 'parallel',
                    '@done'  => 'done',
                    'states' => [
                        'region_a' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'on' => ['FINISH_A' => 'completed'],
                                ],
                                'completed' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'sub_process',
                            'states'  => [
                                'sub_process' => [
                                    'initial' => 'checking',
                                    '@done'   => [
                                        'target'  => 'reviewed',
                                        'actions' => 'logOnDoneTransitionAction',
                                    ],
                                    'states' => [
                                        'checking' => [
                                            'on' => ['CHECK_DONE' => 'verified'],
                                        ],
                                        'verified' => ['type' => 'final'],
                                    ],
                                ],
                                'reviewed' => [
                                    'on' => ['FINISH_B' => 'completed'],
                                ],
                                'completed' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'logOnDoneTransitionAction' => function () use (&$actionLog): void {
                    $actionLog[] = 'oncompletedction_fired';
                },
            ],
        ]
    );

    $state = $definition->getInitialState();

    // Trigger compound onDone
    $state = $definition->transition(['type' => 'CHECK_DONE'], $state);
    expect($state->matches('processing.region_b.reviewed'))->toBeTrue();

    // The onDone action should have fired during the transition
    expect($actionLog)->toContain('oncompletedction_fired');
});

test('chained compound onDone across multiple levels', function (): void {
    // compound_a → final → onDone → compound_b → initial is final → onDone → step3
    $definition = MachineDefinition::define([
        'id'      => 'chained_ondone',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 'all_done',
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => [
                                'on' => ['FINISH_A' => 'completed'],
                            ],
                            'completed' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'phase1',
                        'states'  => [
                            'phase1' => [
                                'initial' => 'running',
                                '@done'   => 'phase2',
                                'states'  => [
                                    'running' => [
                                        'on' => ['PHASE1_DONE' => 'phase1_final'],
                                    ],
                                    'phase1_final' => ['type' => 'final'],
                                ],
                            ],
                            'phase2' => [
                                'initial' => 'auto_complete',
                                '@done'   => 'phase3',
                                'states'  => [
                                    // Initial state is immediately final → triggers chained onDone
                                    'auto_complete' => ['type' => 'final'],
                                ],
                            ],
                            'phase3' => [
                                'on' => ['FINISH_B' => 'completed'],
                            ],
                            'completed' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'all_done' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();
    expect($state->matches('processing.region_b.phase1.running'))->toBeTrue();

    // Trigger phase1 final → onDone → phase2 → initial is final → onDone → phase3
    $state = $definition->transition(['type' => 'PHASE1_DONE'], $state);

    // Should have chained through: phase1.onDone → phase2 → phase2.onDone → phase3
    expect($state->matches('processing.region_b.phase3'))->toBeTrue(
        'Chained compound onDone should resolve: phase1→phase2(auto-final)→phase3'
    );
});

test('region where initial state is immediately final triggers parallel onDone', function (): void {
    // Degenerate case: a region's initial (and only) state is final.
    // When entering the parallel state, this region is already final.
    // If the other region also completes, parallel onDone should fire.
    $definition = MachineDefinition::define([
        'id'      => 'immediate_final',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 'done',
                'states' => [
                    'auto_region' => [
                        'initial' => 'completed',
                        'states'  => [
                            'completed' => ['type' => 'final'],
                        ],
                    ],
                    'manual_region' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => [
                                'on' => ['FINISH' => 'completed'],
                            ],
                            'completed' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'done' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    // auto_region is already at its final state from initialization
    expect($state->matches('processing.auto_region.completed'))->toBeTrue();
    expect($state->matches('processing.manual_region.working'))->toBeTrue();

    // Complete the manual region → both final → parallel onDone should fire
    $state = $definition->transition(['type' => 'FINISH'], $state);
    expect($state->matches('done'))->toBeTrue(
        'Parallel onDone should fire when remaining region reaches final'
    );
});

test('compound sub-state onDone transitions within a parallel region', function (): void {
    // When a compound sub-state's child reaches a final state, the compound state's
    // onDone should fire and transition to the next state within the region.
    // Example: customer.findeks has onDone → under_policy_review
    $definition = MachineDefinition::define([
        'id'      => 'workflow',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 'done',
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => [
                                'on' => [
                                    'FINISH_A' => 'completed',
                                ],
                            ],
                            'completed' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'step1',
                        'states'  => [
                            'step1' => [
                                'on' => [
                                    'START_SUB' => 'sub_process',
                                ],
                            ],
                            'sub_process' => [
                                'initial' => 'checking',
                                '@done'   => 'step3',
                                'states'  => [
                                    'checking' => [
                                        'on' => [
                                            'CHECK_DONE' => 'verified',
                                        ],
                                    ],
                                    'verified' => ['type' => 'final'],
                                ],
                            ],
                            'step3' => [
                                'on' => [
                                    'FINISH_B' => 'completed',
                                ],
                            ],
                            'completed' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'done' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();
    expect($state->matches('processing.region_a.working'))->toBeTrue();
    expect($state->matches('processing.region_b.step1'))->toBeTrue();

    // Enter the compound sub-state
    $state = $definition->transition(['type' => 'START_SUB'], $state);
    expect($state->matches('processing.region_b.sub_process.checking'))->toBeTrue();

    // Complete the sub-process — its child reaches final state
    $state = $definition->transition(['type' => 'CHECK_DONE'], $state);

    // Sub-process's onDone should fire, transitioning region_b to step3
    expect($state->matches('processing.region_b.step3'))->toBeTrue(
        'Compound sub-state onDone should transition to the next state within the region'
    );

    // The parallel state's onDone should NOT have fired (region_a not final)
    expect($state->matches('done'))->toBeFalse();

    // Now complete both regions
    $state = $definition->transition(['type' => 'FINISH_A'], $state);
    expect($state->matches('processing.region_a.completed'))->toBeTrue();
    expect($state->matches('processing.region_b.step3'))->toBeTrue();

    $state = $definition->transition(['type' => 'FINISH_B'], $state);

    // NOW the parallel state's onDone should fire
    expect($state->matches('done'))->toBeTrue();
});
