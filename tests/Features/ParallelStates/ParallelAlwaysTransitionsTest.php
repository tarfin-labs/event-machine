<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/*
 * Tests for @always transitions in parallel states, especially cross-region
 * synchronization where one region waits for another to reach a certain state.
 *
 * Inspired by XState's parallel + stateIn guard pattern (SCXML spec):
 * "By using in guards it is possible to coordinate the different regions."
 */

test('always transition with guard in parallel state does not throw when guard fails', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'cross_region',
            'initial' => 'processing',
            'states'  => [
                'processing' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'waiting',
                            'states'  => [
                                'waiting' => [
                                    'on' => [
                                        '@always' => [
                                            ['target' => 'done', 'guards' => 'isRegionBReadyGuard'],
                                        ],
                                    ],
                                ],
                                'done' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'pending',
                            'states'  => [
                                'pending' => [
                                    'on' => [
                                        'COMPLETE_B' => 'ready',
                                    ],
                                ],
                                'ready' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'guards' => [
                'isRegionBReadyGuard' => fn (ContextManager $ctx, EventBehavior $event, State $state) => $state->matches('processing.region_b.ready'),
            ],
        ]
    );

    // Initial state — region_a has @always but guard should fail (region_b not ready)
    $state = $definition->getInitialState();

    expect($state->matches('processing.region_a.waiting'))->toBeTrue();
    expect($state->matches('processing.region_b.pending'))->toBeTrue();
});

test('always transition fires when cross-region guard becomes satisfied', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'cross_region_sync',
            'initial' => 'processing',
            'states'  => [
                'processing' => [
                    'type'   => 'parallel',
                    'onDone' => 'completed',
                    'states' => [
                        'dealer' => [
                            'initial' => 'pricing',
                            'states'  => [
                                'pricing' => [
                                    'on' => [
                                        'PRICING_DONE' => 'awaiting_approval',
                                    ],
                                ],
                                'awaiting_approval' => [
                                    'on' => [
                                        '@always' => [
                                            ['target' => 'payment_options', 'guards' => 'isPreApprovalPassedGuard'],
                                        ],
                                    ],
                                ],
                                'payment_options' => [
                                    'on' => [
                                        'PAYMENT_SELECTED' => 'dealer_done',
                                    ],
                                ],
                                'dealer_done' => ['type' => 'final'],
                            ],
                        ],
                        'customer' => [
                            'initial' => 'consent',
                            'states'  => [
                                'consent' => [
                                    'on' => [
                                        'CONSENT_GIVEN' => 'policy_check',
                                    ],
                                ],
                                'policy_check' => [
                                    'on' => [
                                        'POLICY_APPROVED' => 'personal_details',
                                    ],
                                ],
                                'personal_details' => [
                                    'on' => [
                                        'DETAILS_SUBMITTED' => 'customer_done',
                                    ],
                                ],
                                'customer_done' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'guards' => [
                'isPreApprovalPassedGuard' => fn (ContextManager $ctx, EventBehavior $event, State $state) => $state->matches('processing.customer.personal_details')
                    || $state->matches('processing.customer.customer_done'),
            ],
        ]
    );

    $state = $definition->getInitialState();

    // Scenario: Dealer finishes pricing first, customer not ready yet
    $state = $definition->transition(['type' => 'PRICING_DONE'], $state);
    expect($state->matches('processing.dealer.awaiting_approval'))->toBeTrue()
        ->and($state->matches('processing.customer.consent'))->toBeTrue();

    // Customer gives consent → policy_check
    $state = $definition->transition(['type' => 'CONSENT_GIVEN'], $state);
    expect($state->matches('processing.dealer.awaiting_approval'))->toBeTrue()
        ->and($state->matches('processing.customer.policy_check'))->toBeTrue();

    // Customer policy approved → personal_details
    // This should trigger dealer's @always guard to pass
    $state = $definition->transition(['type' => 'POLICY_APPROVED'], $state);
    expect($state->matches('processing.dealer.payment_options'))->toBeTrue()
        ->and($state->matches('processing.customer.personal_details'))->toBeTrue();
});

test('always transition fires when region enters awaiting after sibling is already ready', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'reverse_order',
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
                                        'A_DONE' => 'awaiting',
                                    ],
                                ],
                                'awaiting' => [
                                    'on' => [
                                        '@always' => [
                                            ['target' => 'synced', 'guards' => 'isRegionBReadyGuard'],
                                        ],
                                    ],
                                ],
                                'synced' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'on' => [
                                        'B_DONE' => 'ready',
                                    ],
                                ],
                                'ready' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'guards' => [
                'isRegionBReadyGuard' => fn (ContextManager $ctx, EventBehavior $event, State $state) => $state->matches('processing.region_b.ready'),
            ],
        ]
    );

    $state = $definition->getInitialState();

    // Scenario: Region B finishes first
    $state = $definition->transition(['type' => 'B_DONE'], $state);
    expect($state->matches('processing.region_a.working'))->toBeTrue()
        ->and($state->matches('processing.region_b.ready'))->toBeTrue();

    // Region A finishes → enters awaiting → @always guard passes immediately
    $state = $definition->transition(['type' => 'A_DONE'], $state);
    expect($state->matches('processing.region_a.synced'))->toBeTrue()
        ->and($state->matches('processing.region_b.ready'))->toBeTrue();
});

test('mutual cross-region always transitions resolve in sequence', function (): void {
    // XState pattern: two regions check each other's state (microstep chain)
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'mutual',
            'initial' => 'processing',
            'states'  => [
                'processing' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'a1',
                            'states'  => [
                                'a1' => [
                                    'on' => [
                                        'START' => 'a2',
                                    ],
                                ],
                                'a2' => [
                                    'on' => [
                                        '@always' => [
                                            ['target' => 'a3', 'guards' => 'isBAtB2Guard'],
                                        ],
                                    ],
                                ],
                                'a3' => [],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'b1',
                            'states'  => [
                                'b1' => [
                                    'on' => [
                                        'START' => 'b2',
                                    ],
                                ],
                                'b2' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'guards' => [
                'isBAtB2Guard' => fn (ContextManager $ctx, EventBehavior $event, State $state) => $state->matches('processing.region_b.b2'),
            ],
        ]
    );

    $state = $definition->getInitialState();

    // START event transitions both regions: A→a2, B→b2
    // After both transition, @always in a2 should fire because B is at b2
    $state = $definition->transition(['type' => 'START'], $state);
    expect($state->matches('processing.region_a.a3'))->toBeTrue()
        ->and($state->matches('processing.region_b.b2'))->toBeTrue();
});

test('always transition with context flag guard in parallel state', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'context_flag',
            'initial' => 'processing',
            'context' => ['approved' => false],
            'states'  => [
                'processing' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'waiting',
                            'states'  => [
                                'waiting' => [
                                    'on' => [
                                        '@always' => [
                                            ['target' => 'done', 'guards' => 'isApprovedGuard'],
                                        ],
                                    ],
                                ],
                                'done' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'checking',
                            'states'  => [
                                'checking' => [
                                    'on' => [
                                        'APPROVE' => [
                                            'target'  => 'approved',
                                            'actions' => 'setApprovedAction',
                                        ],
                                    ],
                                ],
                                'approved' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'guards' => [
                'isApprovedGuard' => fn (ContextManager $ctx) => $ctx->get('approved') === true,
            ],
            'actions' => [
                'setApprovedAction' => fn (ContextManager $ctx) => $ctx->set('approved', true),
            ],
        ]
    );

    $state = $definition->getInitialState();
    expect($state->matches('processing.region_a.waiting'))->toBeTrue()
        ->and($state->matches('processing.region_b.checking'))->toBeTrue();

    // APPROVE → sets context flag → region_a's @always should fire
    $state = $definition->transition(['type' => 'APPROVE'], $state);
    expect($state->matches('processing.region_a.done'))->toBeTrue()
        ->and($state->matches('processing.region_b.approved'))->toBeTrue();
});

test('always guard failure in parallel does not affect other regions', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'isolated_failure',
            'initial' => 'processing',
            'states'  => [
                'processing' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'waiting',
                            'states'  => [
                                'waiting' => [
                                    'on' => [
                                        '@always' => [
                                            ['target' => 'done', 'guards' => 'neverTrueGuard'],
                                        ],
                                    ],
                                ],
                                'done' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'step1',
                            'states'  => [
                                'step1' => [
                                    'on' => [
                                        'NEXT' => 'step2',
                                    ],
                                ],
                                'step2' => [
                                    'on' => [
                                        'NEXT' => 'step3',
                                    ],
                                ],
                                'step3' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'guards' => [
                'neverTrueGuard' => fn () => false,
            ],
        ]
    );

    $state = $definition->getInitialState();
    expect($state->matches('processing.region_a.waiting'))->toBeTrue()
        ->and($state->matches('processing.region_b.step1'))->toBeTrue();

    // Region B can progress freely even though region_a has a failing @always guard
    $state = $definition->transition(['type' => 'NEXT'], $state);
    expect($state->matches('processing.region_a.waiting'))->toBeTrue()
        ->and($state->matches('processing.region_b.step2'))->toBeTrue();

    $state = $definition->transition(['type' => 'NEXT'], $state);
    expect($state->matches('processing.region_a.waiting'))->toBeTrue()
        ->and($state->matches('processing.region_b.step3'))->toBeTrue();
});

test('cross-region always with onDone completes when all regions reach final', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'cross_region_on_done',
            'initial' => 'processing',
            'states'  => [
                'processing' => [
                    'type'   => 'parallel',
                    'onDone' => 'completed',
                    'states' => [
                        'region_a' => [
                            'initial' => 'waiting',
                            'states'  => [
                                'waiting' => [
                                    'on' => [
                                        '@always' => [
                                            ['target' => 'done', 'guards' => 'isRegionBDoneGuard'],
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
                                        'FINISH' => 'done',
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
            'guards' => [
                'isRegionBDoneGuard' => fn (ContextManager $ctx, EventBehavior $event, State $state) => $state->matches('processing.region_b.done'),
            ],
        ]
    );

    $state = $definition->getInitialState();
    expect($state->matches('processing.region_a.waiting'))->toBeTrue();

    // FINISH → region_b done → @always fires → region_a done → onDone → completed
    $state = $definition->transition(['type' => 'FINISH'], $state);
    expect($state->matches('completed'))->toBeTrue();
});
