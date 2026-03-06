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
            'id'      => 'crossRegion',
            'initial' => 'processing',
            'states'  => [
                'processing' => [
                    'type'   => 'parallel',
                    'states' => [
                        'regionA' => [
                            'initial' => 'waiting',
                            'states'  => [
                                'waiting' => [
                                    'on' => [
                                        '@always' => [
                                            ['target' => 'done', 'guards' => 'isRegionBReady'],
                                        ],
                                    ],
                                ],
                                'done' => ['type' => 'final'],
                            ],
                        ],
                        'regionB' => [
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
                'isRegionBReady' => fn (ContextManager $ctx, EventBehavior $event, State $state) => $state->matches('processing.regionB.ready'),
            ],
        ]
    );

    // Initial state — regionA has @always but guard should fail (regionB not ready)
    $state = $definition->getInitialState();

    expect($state->matches('processing.regionA.waiting'))->toBeTrue();
    expect($state->matches('processing.regionB.pending'))->toBeTrue();
});

test('always transition fires when cross-region guard becomes satisfied', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'crossRegionSync',
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
                                        'PRICING_DONE' => 'awaitingApproval',
                                    ],
                                ],
                                'awaitingApproval' => [
                                    'on' => [
                                        '@always' => [
                                            ['target' => 'paymentOptions', 'guards' => 'isPreApprovalPassed'],
                                        ],
                                    ],
                                ],
                                'paymentOptions' => [
                                    'on' => [
                                        'PAYMENT_SELECTED' => 'dealerDone',
                                    ],
                                ],
                                'dealerDone' => ['type' => 'final'],
                            ],
                        ],
                        'customer' => [
                            'initial' => 'consent',
                            'states'  => [
                                'consent' => [
                                    'on' => [
                                        'CONSENT_GIVEN' => 'policyCheck',
                                    ],
                                ],
                                'policyCheck' => [
                                    'on' => [
                                        'POLICY_APPROVED' => 'personalDetails',
                                    ],
                                ],
                                'personalDetails' => [
                                    'on' => [
                                        'DETAILS_SUBMITTED' => 'customerDone',
                                    ],
                                ],
                                'customerDone' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'guards' => [
                'isPreApprovalPassed' => fn (ContextManager $ctx, EventBehavior $event, State $state) => $state->matches('processing.customer.personalDetails')
                    || $state->matches('processing.customer.customerDone'),
            ],
        ]
    );

    $state = $definition->getInitialState();

    // Scenario: Dealer finishes pricing first, customer not ready yet
    $state = $definition->transition(['type' => 'PRICING_DONE'], $state);
    expect($state->matches('processing.dealer.awaitingApproval'))->toBeTrue()
        ->and($state->matches('processing.customer.consent'))->toBeTrue();

    // Customer gives consent → policyCheck
    $state = $definition->transition(['type' => 'CONSENT_GIVEN'], $state);
    expect($state->matches('processing.dealer.awaitingApproval'))->toBeTrue()
        ->and($state->matches('processing.customer.policyCheck'))->toBeTrue();

    // Customer policy approved → personalDetails
    // This should trigger dealer's @always guard to pass
    $state = $definition->transition(['type' => 'POLICY_APPROVED'], $state);
    expect($state->matches('processing.dealer.paymentOptions'))->toBeTrue()
        ->and($state->matches('processing.customer.personalDetails'))->toBeTrue();
});

test('always transition fires when region enters awaiting after sibling is already ready', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'reverseOrder',
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
                                        'A_DONE' => 'awaiting',
                                    ],
                                ],
                                'awaiting' => [
                                    'on' => [
                                        '@always' => [
                                            ['target' => 'synced', 'guards' => 'isRegionBReady'],
                                        ],
                                    ],
                                ],
                                'synced' => ['type' => 'final'],
                            ],
                        ],
                        'regionB' => [
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
                'isRegionBReady' => fn (ContextManager $ctx, EventBehavior $event, State $state) => $state->matches('processing.regionB.ready'),
            ],
        ]
    );

    $state = $definition->getInitialState();

    // Scenario: Region B finishes first
    $state = $definition->transition(['type' => 'B_DONE'], $state);
    expect($state->matches('processing.regionA.working'))->toBeTrue()
        ->and($state->matches('processing.regionB.ready'))->toBeTrue();

    // Region A finishes → enters awaiting → @always guard passes immediately
    $state = $definition->transition(['type' => 'A_DONE'], $state);
    expect($state->matches('processing.regionA.synced'))->toBeTrue()
        ->and($state->matches('processing.regionB.ready'))->toBeTrue();
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
                        'regionA' => [
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
                                            ['target' => 'a3', 'guards' => 'isBAtB2'],
                                        ],
                                    ],
                                ],
                                'a3' => [],
                            ],
                        ],
                        'regionB' => [
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
                'isBAtB2' => fn (ContextManager $ctx, EventBehavior $event, State $state) => $state->matches('processing.regionB.b2'),
            ],
        ]
    );

    $state = $definition->getInitialState();

    // START event transitions both regions: A→a2, B→b2
    // After both transition, @always in a2 should fire because B is at b2
    $state = $definition->transition(['type' => 'START'], $state);
    expect($state->matches('processing.regionA.a3'))->toBeTrue()
        ->and($state->matches('processing.regionB.b2'))->toBeTrue();
});

test('always transition with context flag guard in parallel state', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'contextFlag',
            'initial' => 'processing',
            'context' => ['approved' => false],
            'states'  => [
                'processing' => [
                    'type'   => 'parallel',
                    'states' => [
                        'regionA' => [
                            'initial' => 'waiting',
                            'states'  => [
                                'waiting' => [
                                    'on' => [
                                        '@always' => [
                                            ['target' => 'done', 'guards' => 'isApproved'],
                                        ],
                                    ],
                                ],
                                'done' => ['type' => 'final'],
                            ],
                        ],
                        'regionB' => [
                            'initial' => 'checking',
                            'states'  => [
                                'checking' => [
                                    'on' => [
                                        'APPROVE' => [
                                            'target'  => 'approved',
                                            'actions' => 'setApproved',
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
                'isApproved' => fn (ContextManager $ctx) => $ctx->get('approved') === true,
            ],
            'actions' => [
                'setApproved' => fn (ContextManager $ctx) => $ctx->set('approved', true),
            ],
        ]
    );

    $state = $definition->getInitialState();
    expect($state->matches('processing.regionA.waiting'))->toBeTrue()
        ->and($state->matches('processing.regionB.checking'))->toBeTrue();

    // APPROVE → sets context flag → regionA's @always should fire
    $state = $definition->transition(['type' => 'APPROVE'], $state);
    expect($state->matches('processing.regionA.done'))->toBeTrue()
        ->and($state->matches('processing.regionB.approved'))->toBeTrue();
});

test('always guard failure in parallel does not affect other regions', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'isolatedFailure',
            'initial' => 'processing',
            'states'  => [
                'processing' => [
                    'type'   => 'parallel',
                    'states' => [
                        'regionA' => [
                            'initial' => 'waiting',
                            'states'  => [
                                'waiting' => [
                                    'on' => [
                                        '@always' => [
                                            ['target' => 'done', 'guards' => 'neverTrue'],
                                        ],
                                    ],
                                ],
                                'done' => ['type' => 'final'],
                            ],
                        ],
                        'regionB' => [
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
                'neverTrue' => fn () => false,
            ],
        ]
    );

    $state = $definition->getInitialState();
    expect($state->matches('processing.regionA.waiting'))->toBeTrue()
        ->and($state->matches('processing.regionB.step1'))->toBeTrue();

    // Region B can progress freely even though regionA has a failing @always guard
    $state = $definition->transition(['type' => 'NEXT'], $state);
    expect($state->matches('processing.regionA.waiting'))->toBeTrue()
        ->and($state->matches('processing.regionB.step2'))->toBeTrue();

    $state = $definition->transition(['type' => 'NEXT'], $state);
    expect($state->matches('processing.regionA.waiting'))->toBeTrue()
        ->and($state->matches('processing.regionB.step3'))->toBeTrue();
});

test('cross-region always with onDone completes when all regions reach final', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'crossRegionOnDone',
            'initial' => 'processing',
            'states'  => [
                'processing' => [
                    'type'   => 'parallel',
                    'onDone' => 'completed',
                    'states' => [
                        'regionA' => [
                            'initial' => 'waiting',
                            'states'  => [
                                'waiting' => [
                                    'on' => [
                                        '@always' => [
                                            ['target' => 'done', 'guards' => 'isRegionBDone'],
                                        ],
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
                'isRegionBDone' => fn (ContextManager $ctx, EventBehavior $event, State $state) => $state->matches('processing.regionB.done'),
            ],
        ]
    );

    $state = $definition->getInitialState();
    expect($state->matches('processing.regionA.waiting'))->toBeTrue();

    // FINISH → regionB done → @always fires → regionA done → onDone → completed
    $state = $definition->transition(['type' => 'FINISH'], $state);
    expect($state->matches('completed'))->toBeTrue();
});
