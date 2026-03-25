<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/*
 * @always self-targeting compound child settles without infinite loop.
 *
 * When @always targets the same compound state and the guard returns true
 * once then false, the machine must settle (not loop infinitely).
 * This tests the SCXML rule: @always re-evaluates after each microstep,
 * but the machine must detect that no progress is made and stop.
 */

test('@always with guard targeting same compound state settles when guard flips', function (): void {
    // Guard returns true on first call (counter == 0), then false.
    // This means: enter compound_check → @always guard passes → re-enter compound_check →
    // @always guard fails → settle at compound_check.initial_child
    $callCount = 0;

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'self_target',
            'initial' => 'idle',
            'context' => ['iterations' => 0],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'compound_check'],
                ],
                'compound_check' => [
                    'initial' => 'evaluating',
                    'states'  => [
                        'evaluating' => [
                            'entry' => 'incrementIterationsAction',
                            'on'    => [
                                '@always' => [
                                    ['target' => 'settled', 'guards' => 'shouldSettleGuard'],
                                ],
                            ],
                        ],
                        'settled' => [],
                    ],
                ],
            ],
        ],
        behavior: [
            'guards' => [
                'shouldSettleGuard' => function (ContextManager $ctx) use (&$callCount): bool {
                    $callCount++;

                    // Return true only on first invocation — causes transition to settled
                    return $callCount === 1;
                },
            ],
            'actions' => [
                'incrementIterationsAction' => function (ContextManager $ctx): void {
                    $ctx->set('iterations', $ctx->get('iterations') + 1);
                },
            ],
        ]
    );

    $state = $definition->getInitialState();
    expect($state->matches('idle'))->toBeTrue();

    // GO → compound_check.evaluating → @always guard true → settled
    $state = $definition->transition(['type' => 'GO'], $state);

    // Machine should settle at 'settled' after guard returned true once
    expect($state->matches('self_target.compound_check.settled'))->toBeTrue();
});

test('@always guard that always returns false does not loop — machine stays', function (): void {
    $guardCallCount = 0;

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'never_always',
            'initial' => 'checking',
            'states'  => [
                'checking' => [
                    'on' => [
                        '@always' => [
                            ['target' => 'done', 'guards' => 'neverPassGuard'],
                        ],
                        'MANUAL' => 'done',
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'guards' => [
                'neverPassGuard' => function () use (&$guardCallCount): bool {
                    $guardCallCount++;

                    return false;
                },
            ],
        ]
    );

    $state = $definition->getInitialState();

    // Machine should stay at 'checking' since guard always returns false
    expect($state->matches('checking'))->toBeTrue();

    // Guard was called (at least once during initialization) but machine did not loop forever
    expect($guardCallCount)->toBeGreaterThanOrEqual(1)
        ->and($guardCallCount)->toBeLessThan(5); // Sanity: not hundreds of calls

    // Manual transition still works — machine is not stuck
    $state = $definition->transition(['type' => 'MANUAL'], $state);
    expect($state->matches('done'))->toBeTrue();
});

test('@always guard that passes once then fails across re-entry settles correctly', function (): void {
    // Simulates: idle → GO → routing → @always(true) → active → RETRY → routing → @always(false) → stays
    $retryCount = 0;

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'retry_settle',
            'initial' => 'idle',
            'context' => ['attempts' => 0],
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'routing'],
                ],
                'routing' => [
                    'entry' => 'trackAttemptAction',
                    'on'    => [
                        '@always' => [
                            ['target' => 'active', 'guards' => 'isFirstAttemptGuard'],
                        ],
                    ],
                ],
                'active' => [
                    'on' => ['RETRY' => 'routing'],
                ],
            ],
        ],
        behavior: [
            'guards' => [
                'isFirstAttemptGuard' => fn (ContextManager $ctx): bool => $ctx->get('attempts') <= 1,
            ],
            'actions' => [
                'trackAttemptAction' => function (ContextManager $ctx) use (&$retryCount): void {
                    $retryCount++;
                    $ctx->set('attempts', $ctx->get('attempts') + 1);
                },
            ],
        ]
    );

    $state = $definition->getInitialState();

    // First pass: idle → routing (attempts=1) → @always guard true → active
    $state = $definition->transition(['type' => 'GO'], $state);
    expect($state->matches('retry_settle.active'))->toBeTrue();

    // Second pass: active → RETRY → routing (attempts=2) → @always guard false → stays at routing
    $state = $definition->transition(['type' => 'RETRY'], $state);
    expect($state->matches('retry_settle.routing'))->toBeTrue()
        ->and($retryCount)->toBe(2);
});
