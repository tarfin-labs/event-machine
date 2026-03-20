<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

// === Parallel Integration — Event Preservation ===

test('sync parallel with @always in region preserves triggering event in entry action', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'parallel_always_event',
            'initial' => 'idle',
            'context' => [
                'region_a_event_type'    => null,
                'region_a_event_payload' => null,
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'START' => 'processing',
                    ],
                ],
                'processing' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'routing',
                            'states'  => [
                                'routing' => [
                                    'on' => [
                                        '@always' => [
                                            'target'  => 'done',
                                            'actions' => 'captureRegionAEventAction',
                                        ],
                                    ],
                                ],
                                'done' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'ready',
                            'states'  => [
                                'ready' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'captureRegionAEventAction' => function (ContextManager $ctx, EventBehavior $event): void {
                    $ctx->set('region_a_event_type', $event->type);
                    $ctx->set('region_a_event_payload', $event->payload);
                },
            ],
        ]
    );

    $state = $definition->getInitialState();

    $state = $definition->transition([
        'type'    => 'START',
        'payload' => ['order_id' => 42],
    ], $state);

    // @always action in region_a should receive the original START event
    expect($state->context->get('region_a_event_type'))->toBe('START');
    expect($state->context->get('region_a_event_payload'))->toBe(['order_id' => 42]);
});

test('async parallel dispatch loses transient triggering event — no regression', function (): void {
    // triggeringEvent is a transient property (not persisted to DB).
    // When State is reconstructed from DB (as happens in async ParallelRegionJob),
    // triggeringEvent is null. This test verifies the transient nature by checking
    // that a fresh state has null triggeringEvent.
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'async_parallel_always',
            'initial' => 'idle',
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'processing'],
                ],
                'processing' => [],
            ],
        ],
    );

    $state = $definition->getInitialState();

    // Before any transition, triggeringEvent is null
    expect($state->triggeringEvent)->toBeNull();

    // After transition, triggeringEvent is set
    $state = $definition->transition(['type' => 'GO'], $state);
    expect($state->triggeringEvent)->not->toBeNull();
    expect($state->triggeringEvent->type)->toBe('GO');

    // Simulate async reconstruction: triggeringEvent would be null
    // because it's not in jsonSerialize() output
    $serialized = $state->jsonSerialize();
    expect($serialized)->not->toHaveKey('triggeringEvent');
});

test('cross-region @always guard receives triggering event', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'cross_region_event',
            'initial' => 'idle',
            'context' => [
                'guard_received_event_type'    => null,
                'guard_received_event_payload' => null,
            ],
            'states' => [
                'idle' => [
                    'on' => ['SUBMIT' => 'processing'],
                ],
                'processing' => [
                    'type'   => 'parallel',
                    '@done'  => 'completed',
                    'states' => [
                        'region_a' => [
                            'initial' => 'waiting',
                            'states'  => [
                                'waiting' => [
                                    'on' => [
                                        '@always' => [
                                            ['target' => 'done', 'guards' => 'captureAndPassGuard'],
                                        ],
                                    ],
                                ],
                                'done' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'ready',
                            'states'  => [
                                'ready' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'guards' => [
                'captureAndPassGuard' => function (ContextManager $ctx, EventBehavior $event, State $state): bool {
                    $ctx->set('guard_received_event_type', $event->type);
                    $ctx->set('guard_received_event_payload', $event->payload);

                    // Only pass if region_b is ready (cross-region check)
                    return $state->matches('processing.region_b.ready');
                },
            ],
        ]
    );

    $state = $definition->getInitialState();

    $state = $definition->transition([
        'type'    => 'SUBMIT',
        'payload' => ['tckn' => '12345678901'],
    ], $state);

    // Guard should have received the original SUBMIT event, not @always
    expect($state->context->get('guard_received_event_type'))->toBe('SUBMIT');
    expect($state->context->get('guard_received_event_payload'))
        ->toBe(['tckn' => '12345678901']);

    // Both regions done → @done fires → completed
    expect($state->matches('completed'))->toBeTrue();
});
