<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Test that @always transitions evaluate guards in array order
 * and the first transition whose guard returns true wins.
 *
 * Inspired by XState transient.test.ts — always transition guard ordering.
 */

// ── Helpers ──────────────────────────────────────────────────────

function alwaysGuardOrderDefinition(array $guardOutcomes): MachineDefinition
{
    $alwaysTransitions = [];
    $states            = [
        'check' => ['on' => []],
    ];

    foreach ($guardOutcomes as $index => $result) {
        $targetState = 'target_'.$index;
        $guardKey    = 'guard_'.$index;

        $alwaysTransitions[] = [
            'target' => $targetState,
            'guards' => $guardKey,
        ];

        $states[$targetState] = ['type' => 'final'];
    }

    $states['check']['on']['@always'] = $alwaysTransitions;

    $guards = [];
    foreach ($guardOutcomes as $index => $result) {
        $guards['guard_'.$index] = fn (): bool => $result;
    }

    return MachineDefinition::define(
        config: [
            'id'      => 'always_guard_order',
            'initial' => 'check',
            'states'  => $states,
        ],
        behavior: [
            'guards' => $guards,
        ],
    );
}

// ── Tests ────────────────────────────────────────────────────────

it('selects the first @always transition whose guard returns true (second in array)', function (): void {
    // Guard 0 = false, Guard 1 = true, Guard 2 = true
    $state = alwaysGuardOrderDefinition([false, true, true])->getInitialState();

    // The second transition (index 1) should win — first true guard in array order
    expect($state->value)->toBe(['always_guard_order.target_1']);
});

it('selects the first @always transition when its guard returns true', function (): void {
    // Guard 0 = true, Guard 1 = true, Guard 2 = false
    $state = alwaysGuardOrderDefinition([true, true, false])->getInitialState();

    // The first transition (index 0) should win
    expect($state->value)->toBe(['always_guard_order.target_0']);
});

it('selects the last @always transition when only its guard returns true', function (): void {
    // Guard 0 = false, Guard 1 = false, Guard 2 = true
    $state = alwaysGuardOrderDefinition([false, false, true])->getInitialState();

    // The third transition (index 2) should win
    expect($state->value)->toBe(['always_guard_order.target_2']);
});

it('stays in check state when no @always guard returns true', function (): void {
    // All guards return false — no transition should fire
    $state = alwaysGuardOrderDefinition([false, false, false])->getInitialState();

    // Machine remains in check state
    expect($state->value)->toBe(['always_guard_order.check']);
});

it('respects reordered guards — different order yields different target', function (): void {
    // Original order: first=false, second=true → lands on target_1
    $stateA = alwaysGuardOrderDefinition([false, true])->getInitialState();
    expect($stateA->value)->toBe(['always_guard_order.target_1']);

    // Reversed effective order: first=true, second=false → lands on target_0
    $stateB = alwaysGuardOrderDefinition([true, false])->getInitialState();
    expect($stateB->value)->toBe(['always_guard_order.target_0']);
});

it('evaluates context-dependent guards in array order (first-match wins)', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'always_context_guard_order',
            'initial' => 'evaluate',
            'context' => [
                'score' => 75,
            ],
            'states' => [
                'evaluate' => [
                    'on' => [
                        '@always' => [
                            [
                                'target' => 'excellent',
                                'guards' => 'isExcellentGuard',
                            ],
                            [
                                'target' => 'passing',
                                'guards' => 'isPassingGuard',
                            ],
                            [
                                'target' => 'failing',
                            ],
                        ],
                    ],
                ],
                'excellent' => ['type' => 'final'],
                'passing'   => ['type' => 'final'],
                'failing'   => ['type' => 'final'],
            ],
        ],
        behavior: [
            'guards' => [
                'isExcellentGuard' => fn (ContextManager $ctx): bool => $ctx->get('score') >= 90,
                'isPassingGuard'   => fn (ContextManager $ctx): bool => $ctx->get('score') >= 60,
            ],
        ],
    );

    // score=75: excellent guard fails (75 < 90), passing guard succeeds (75 >= 60)
    $state = $definition->getInitialState();
    expect($state->value)->toBe(['always_context_guard_order.passing']);
});
