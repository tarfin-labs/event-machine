<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

// ============================================================
// Entry Action Data Flows to Subsequent @always Chain
// ============================================================
// State entry action sets context value, @always guard reads
// that value to decide transition target.

it('entry action sets context that @always guard uses to select target', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'entry_always_flow',
            'initial' => 'idle',
            'context' => [
                'score' => 0,
            ],
            'states' => [
                'idle' => [
                    'on' => ['EVALUATE' => 'evaluating'],
                ],
                'evaluating' => [
                    'entry' => 'computeScoreAction',
                    'on'    => [
                        '@always' => [
                            ['target' => 'excellent', 'guards' => 'isExcellentGuard'],
                            ['target' => 'passing',   'guards' => 'isPassingGuard'],
                            ['target' => 'failing'],
                        ],
                    ],
                ],
                'excellent' => ['type' => 'final'],
                'passing'   => ['type' => 'final'],
                'failing'   => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'computeScoreAction' => function (ContextManager $ctx): void {
                    // Entry action sets score=85
                    $ctx->set('score', 85);
                },
            ],
            'guards' => [
                'isExcellentGuard' => fn (ContextManager $ctx): bool => $ctx->get('score') >= 90,
                'isPassingGuard'   => fn (ContextManager $ctx): bool => $ctx->get('score') >= 60,
            ],
        ],
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(event: ['type' => 'EVALUATE'], state: $state);

    // Entry action set score=85 → excellent guard fails (85 < 90), passing guard succeeds (85 >= 60)
    expect($state->value)->toBe(['entry_always_flow.passing'])
        ->and($state->context->get('score'))->toBe(85);
});

it('entry action setting high score routes to excellent via @always', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'entry_always_high',
            'initial' => 'evaluating',
            'context' => [
                'score' => 0,
            ],
            'states' => [
                'evaluating' => [
                    'entry' => 'setHighScoreAction',
                    'on'    => [
                        '@always' => [
                            ['target' => 'excellent', 'guards' => 'isExcellentGuard'],
                            ['target' => 'passing',   'guards' => 'isPassingGuard'],
                            ['target' => 'failing'],
                        ],
                    ],
                ],
                'excellent' => ['type' => 'final'],
                'passing'   => ['type' => 'final'],
                'failing'   => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'setHighScoreAction' => function (ContextManager $ctx): void {
                    $ctx->set('score', 95);
                },
            ],
            'guards' => [
                'isExcellentGuard' => fn (ContextManager $ctx): bool => $ctx->get('score') >= 90,
                'isPassingGuard'   => fn (ContextManager $ctx): bool => $ctx->get('score') >= 60,
            ],
        ],
    );

    // On initial state, entry action sets score=95 → excellent guard succeeds
    $state = $definition->getInitialState();

    expect($state->value)->toBe(['entry_always_high.excellent'])
        ->and($state->context->get('score'))->toBe(95);
});

it('entry action setting low score falls through to default @always target', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'entry_always_low',
            'initial' => 'evaluating',
            'context' => [
                'score' => 0,
            ],
            'states' => [
                'evaluating' => [
                    'entry' => 'setLowScoreAction',
                    'on'    => [
                        '@always' => [
                            ['target' => 'excellent', 'guards' => 'isExcellentGuard'],
                            ['target' => 'passing',   'guards' => 'isPassingGuard'],
                            ['target' => 'failing'],
                        ],
                    ],
                ],
                'excellent' => ['type' => 'final'],
                'passing'   => ['type' => 'final'],
                'failing'   => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'setLowScoreAction' => function (ContextManager $ctx): void {
                    $ctx->set('score', 30);
                },
            ],
            'guards' => [
                'isExcellentGuard' => fn (ContextManager $ctx): bool => $ctx->get('score') >= 90,
                'isPassingGuard'   => fn (ContextManager $ctx): bool => $ctx->get('score') >= 60,
            ],
        ],
    );

    // score=30 → both guards fail → falls through to 'failing' (no guard = default)
    $state = $definition->getInitialState();

    expect($state->value)->toBe(['entry_always_low.failing'])
        ->and($state->context->get('score'))->toBe(30);
});

it('entry action with multiple context changes feeds chained @always transitions', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'entry_always_chain',
            'initial' => 'step_one',
            'context' => [
                'level'  => 0,
                'status' => 'unknown',
            ],
            'states' => [
                'step_one' => [
                    'entry' => 'initializeLevelAction',
                    'on'    => [
                        '@always' => [
                            'target' => 'step_two',
                        ],
                    ],
                ],
                'step_two' => [
                    'entry' => 'classifyStatusAction',
                    'on'    => [
                        '@always' => [
                            ['target' => 'premium',  'guards' => 'isPremiumGuard'],
                            ['target' => 'standard', 'guards' => 'isStandardGuard'],
                            ['target' => 'basic'],
                        ],
                    ],
                ],
                'premium'  => ['type' => 'final'],
                'standard' => ['type' => 'final'],
                'basic'    => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'initializeLevelAction' => function (ContextManager $ctx): void {
                    $ctx->set('level', 5);
                },
                'classifyStatusAction' => function (ContextManager $ctx): void {
                    // Uses level set by previous entry action to determine status
                    $level = $ctx->get('level');
                    $ctx->set('status', $level >= 5 ? 'premium_eligible' : 'standard');
                },
            ],
            'guards' => [
                'isPremiumGuard'  => fn (ContextManager $ctx): bool => $ctx->get('status') === 'premium_eligible',
                'isStandardGuard' => fn (ContextManager $ctx): bool => $ctx->get('status') === 'standard',
            ],
        ],
    );

    // step_one entry sets level=5 → @always → step_two entry sets status=premium_eligible
    // → @always → premium (isPremiumGuard passes)
    $state = $definition->getInitialState();

    expect($state->value)->toBe(['entry_always_chain.premium'])
        ->and($state->context->get('level'))->toBe(5)
        ->and($state->context->get('status'))->toBe('premium_eligible');
});

it('context initial value is overridden by entry action before @always guard evaluation', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'entry_always_override',
            'initial' => 'checking',
            'context' => [
                'approved' => false,
            ],
            'states' => [
                'checking' => [
                    'entry' => 'approveAction',
                    'on'    => [
                        '@always' => [
                            ['target' => 'approved_state', 'guards' => 'isApprovedGuard'],
                            ['target' => 'rejected_state'],
                        ],
                    ],
                ],
                'approved_state' => ['type' => 'final'],
                'rejected_state' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'approveAction' => function (ContextManager $ctx): void {
                    $ctx->set('approved', true);
                },
            ],
            'guards' => [
                'isApprovedGuard' => fn (ContextManager $ctx): bool => $ctx->get('approved') === true,
            ],
        ],
    );

    // Context starts with approved=false, but entry action overrides to true.
    // @always guard sees the updated value.
    $state = $definition->getInitialState();

    expect($state->value)->toBe(['entry_always_override.approved_state'])
        ->and($state->context->get('approved'))->toBeTrue();
});
