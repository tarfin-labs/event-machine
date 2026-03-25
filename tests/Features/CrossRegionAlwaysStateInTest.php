<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/*
 * Cross-region @always with stateIn guards.
 *
 * SCXML pattern: @always transition in one region uses a guard that
 * checks a sibling region's current state via $state->matches().
 * This is the canonical way to coordinate parallel regions.
 */

test('@always with stateIn guard fires when sibling region reaches target state', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'cross_state_in',
            'initial' => 'processing',
            'states'  => [
                'processing' => [
                    'type'   => 'parallel',
                    '@done'  => 'completed',
                    'states' => [
                        'approval' => [
                            'initial' => 'awaiting_review',
                            'states'  => [
                                'awaiting_review' => [
                                    'on' => [
                                        '@always' => [
                                            ['target' => 'approved', 'guards' => 'isDocumentsVerifiedGuard'],
                                        ],
                                    ],
                                ],
                                'approved' => ['type' => 'final'],
                            ],
                        ],
                        'documents' => [
                            'initial' => 'uploading',
                            'states'  => [
                                'uploading' => [
                                    'on' => ['UPLOAD_DONE' => 'verifying'],
                                ],
                                'verifying' => [
                                    'on' => ['VERIFY_DONE' => 'verified'],
                                ],
                                'verified' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'guards' => [
                'isDocumentsVerifiedGuard' => fn (ContextManager $ctx, EventBehavior $event, State $state): bool => $state->matches('processing.documents.verified'),
            ],
        ]
    );

    $state = $definition->getInitialState();

    // Initially both regions are in their starting states
    expect($state->matches('processing.approval.awaiting_review'))->toBeTrue()
        ->and($state->matches('processing.documents.uploading'))->toBeTrue();

    // Upload docs — approval still waiting (documents not verified yet)
    $state = $definition->transition(['type' => 'UPLOAD_DONE'], $state);
    expect($state->matches('processing.approval.awaiting_review'))->toBeTrue()
        ->and($state->matches('processing.documents.verifying'))->toBeTrue();

    // Verify docs — approval's @always guard should now pass
    $state = $definition->transition(['type' => 'VERIFY_DONE'], $state);
    expect($state->matches('processing.approval.approved'))->toBeTrue()
        ->and($state->matches('processing.documents.verified'))->toBeTrue();

    // Both regions final → @done fires
    expect($state->matches('completed'))->toBeTrue();
});

test('@always stateIn guard does NOT fire when sibling is in intermediate state', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'state_in_intermediate',
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
                                            ['target' => 'released', 'guards' => 'isBAtStep3Guard'],
                                        ],
                                    ],
                                ],
                                'released' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'step1',
                            'states'  => [
                                'step1' => [
                                    'on' => ['NEXT' => 'step2'],
                                ],
                                'step2' => [
                                    'on' => ['NEXT' => 'step3'],
                                ],
                                'step3' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'guards' => [
                'isBAtStep3Guard' => fn (ContextManager $ctx, EventBehavior $event, State $state): bool => $state->matches('processing.region_b.step3'),
            ],
        ]
    );

    $state = $definition->getInitialState();

    // region_b at step1 — guard should NOT pass
    expect($state->matches('processing.region_a.waiting'))->toBeTrue()
        ->and($state->matches('processing.region_b.step1'))->toBeTrue();

    // Move region_b to step2 — guard still should NOT pass
    $state = $definition->transition(['type' => 'NEXT'], $state);
    expect($state->matches('processing.region_a.waiting'))->toBeTrue()
        ->and($state->matches('processing.region_b.step2'))->toBeTrue();

    // Move region_b to step3 — NOW guard should pass
    $state = $definition->transition(['type' => 'NEXT'], $state);
    expect($state->matches('processing.region_a.released'))->toBeTrue()
        ->and($state->matches('processing.region_b.step3'))->toBeTrue();
});

test('bidirectional stateIn guards — both regions wait for each other via context', function (): void {
    // Both regions check context flags set by the other region's actions.
    // region_a waits for b_ready flag, region_b waits for a_ready flag.
    // When both flags are set, both regions should advance.
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'bidirectional',
            'initial' => 'processing',
            'context' => ['a_ready' => false, 'b_ready' => false],
            'states'  => [
                'processing' => [
                    'type'   => 'parallel',
                    '@done'  => 'done',
                    'states' => [
                        'region_a' => [
                            'initial' => 'preparing',
                            'states'  => [
                                'preparing' => [
                                    'on' => [
                                        'A_PREPARED' => [
                                            'target'  => 'awaiting_b',
                                            'actions' => 'setAReadyAction',
                                        ],
                                    ],
                                ],
                                'awaiting_b' => [
                                    'on' => [
                                        '@always' => [
                                            ['target' => 'completed', 'guards' => 'isBReadyGuard'],
                                        ],
                                    ],
                                ],
                                'completed' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'preparing',
                            'states'  => [
                                'preparing' => [
                                    'on' => [
                                        'B_PREPARED' => [
                                            'target'  => 'awaiting_a',
                                            'actions' => 'setBReadyAction',
                                        ],
                                    ],
                                ],
                                'awaiting_a' => [
                                    'on' => [
                                        '@always' => [
                                            ['target' => 'completed', 'guards' => 'isAReadyGuard'],
                                        ],
                                    ],
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
            'guards' => [
                'isAReadyGuard' => fn (ContextManager $ctx): bool => $ctx->get('a_ready') === true,
                'isBReadyGuard' => fn (ContextManager $ctx): bool => $ctx->get('b_ready') === true,
            ],
            'actions' => [
                'setAReadyAction' => fn (ContextManager $ctx) => $ctx->set('a_ready', true),
                'setBReadyAction' => fn (ContextManager $ctx) => $ctx->set('b_ready', true),
            ],
        ]
    );

    $state = $definition->getInitialState();

    // A prepares first — sets a_ready, enters awaiting_b (b not ready yet)
    $state = $definition->transition(['type' => 'A_PREPARED'], $state);
    expect($state->matches('processing.region_a.awaiting_b'))->toBeTrue()
        ->and($state->matches('processing.region_b.preparing'))->toBeTrue();

    // B prepares — sets b_ready, enters awaiting_a
    // At this point: a_ready=true, b_ready=true
    // region_b's @always (isAReadyGuard) should pass → completed
    // region_a's @always (isBReadyGuard) should also pass → completed
    // Both final → @done → done
    $state = $definition->transition(['type' => 'B_PREPARED'], $state);
    expect($state->matches('done'))->toBeTrue();
});
