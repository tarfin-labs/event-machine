<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\RaiseNotifyAction;

test('raised event in one region triggers transition in sibling region (sync mode)', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'cross_region_raise',
            'initial' => 'active',
            'context' => [
                'region_a_entered'  => false,
                'region_b_notified' => false,
            ],
            'states' => [
                'active' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'idle',
                            'states'  => [
                                'idle' => [
                                    'entry' => RaiseNotifyAction::class,
                                    'on'    => [
                                        'NOTIFY' => 'notified',
                                    ],
                                ],
                                'notified' => [],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'waiting',
                            'states'  => [
                                'waiting' => [
                                    'on' => [
                                        'NOTIFY' => [
                                            'target'  => 'received',
                                            'actions' => 'markNotifiedAction',
                                        ],
                                    ],
                                ],
                                'received' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'markNotifiedAction' => function (ContextManager $ctx): void {
                    $ctx->set('region_b_notified', true);
                },
            ],
        ],
    );

    $state = $definition->getInitialState();

    // Region A's entry action raised NOTIFY
    // NOTIFY should propagate to region B, transitioning it from waiting to received
    expect($state->context->get('region_a_entered'))->toBeTrue();
    expect($state->context->get('region_b_notified'))->toBeTrue();
    expect($state->matches('active.region_a.notified'))->toBeTrue();
    expect($state->matches('active.region_b.received'))->toBeTrue();
});

test('raised event only affects regions that handle it', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'selective_cross_raise',
            'initial' => 'active',
            'context' => [
                'region_a_entered' => false,
            ],
            'states' => [
                'active' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'idle',
                            'states'  => [
                                'idle' => [
                                    'entry' => RaiseNotifyAction::class,
                                    'on'    => [
                                        'NOTIFY' => 'notified',
                                    ],
                                ],
                                'notified' => [],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'waiting',
                            'states'  => [
                                'waiting' => [],
                            ],
                        ],
                        'region_c' => [
                            'initial' => 'listening',
                            'states'  => [
                                'listening' => [
                                    'on' => [
                                        'NOTIFY' => 'alerted',
                                    ],
                                ],
                                'alerted' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    );

    $state = $definition->getInitialState();

    // Region A raised NOTIFY and transitioned itself
    expect($state->matches('active.region_a.notified'))->toBeTrue();

    // Region B has no handler for NOTIFY — stays in waiting
    expect($state->matches('active.region_b.waiting'))->toBeTrue();

    // Region C handles NOTIFY — transitions to alerted
    expect($state->matches('active.region_c.alerted'))->toBeTrue();
});

test('context changes from raise action are visible across regions', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'context_cross_raise',
            'initial' => 'active',
            'context' => [
                'region_a_entered'     => false,
                'region_b_saw_context' => false,
            ],
            'states' => [
                'active' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'idle',
                            'states'  => [
                                'idle' => [
                                    'entry' => RaiseNotifyAction::class,
                                    'on'    => [
                                        'NOTIFY' => 'done',
                                    ],
                                ],
                                'done' => [],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'waiting',
                            'states'  => [
                                'waiting' => [
                                    'on' => [
                                        'NOTIFY' => [
                                            'target'  => 'done',
                                            'actions' => 'checkContextAction',
                                        ],
                                    ],
                                ],
                                'done' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'checkContextAction' => function (ContextManager $ctx): void {
                    // Region A's entry action set region_a_entered = true before raising
                    // That context change should be visible here
                    $ctx->set('region_b_saw_context', $ctx->get('region_a_entered') === true);
                },
            ],
        ],
    );

    $state = $definition->getInitialState();

    expect($state->context->get('region_a_entered'))->toBeTrue();
    expect($state->context->get('region_b_saw_context'))->toBeTrue();
    expect($state->matches('active.region_a.done'))->toBeTrue();
    expect($state->matches('active.region_b.done'))->toBeTrue();
});
