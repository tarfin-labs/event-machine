<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\FailingChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateChildMachine;

// ============================================================
// Parallel Isolated Fail Test (XState Pass 4)
// ============================================================

it('parallel @fail only fires in the region that invoked the failed child, sibling region unaffected', function (): void {
    // Parallel machine with two regions:
    //   region_a: delegates to FailingChildMachine (throws), has @fail → region_a_failed (final)
    //   region_b: delegates to ImmediateChildMachine (succeeds), @done → region_b_done (final)
    //
    // When region_a's child fails, @fail should handle it within region_a only.
    // Region_b should complete normally.
    // Since both regions reach final states (one via @fail, one via @done), @done on
    // the parallel state fires.

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'parallel_isolated_fail',
            'initial' => 'processing',
            'context' => [
                'region_a_error' => null,
            ],
            'states' => [
                'processing' => [
                    'type'   => 'parallel',
                    '@done'  => 'completed',
                    'states' => [
                        'region_a' => [
                            'initial' => 'invoking',
                            'states'  => [
                                'invoking' => [
                                    'machine' => FailingChildMachine::class,
                                    '@done'   => 'region_a_done',
                                    '@fail'   => [
                                        'target'  => 'region_a_failed',
                                        'actions' => 'captureRegionAErrorAction',
                                    ],
                                ],
                                'region_a_done'   => ['type' => 'final'],
                                'region_a_failed' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'invoking',
                            'states'  => [
                                'invoking' => [
                                    'machine' => ImmediateChildMachine::class,
                                    '@done'   => 'region_b_done',
                                ],
                                'region_b_done' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'captureRegionAErrorAction' => function (ContextManager $ctx, EventBehavior $event): void {
                    $ctx->set('region_a_error', $event->payload['error_message'] ?? 'unknown');
                },
            ],
        ],
    );

    $state = $definition->getInitialState();

    // The machine should have processed both regions:
    // - region_a: child failed → @fail → region_a_failed (final)
    // - region_b: child succeeded → @done → region_b_done (final)
    // Both regions reached final states → parallel @done fires → completed
    expect($state->value)->toBe(['parallel_isolated_fail.completed'])
        ->and($state->context->get('region_a_error'))->toBe('Payment gateway down');
});
