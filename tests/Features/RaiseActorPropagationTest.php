<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\RaiseActorPropagation\RaiseChainAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\RaiseActorPropagation\CaptureActorAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\RaiseActorPropagation\RaiseWithoutActorAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\RaiseActorPropagation\RaiseWithExplicitActorAction;

// region Scenario 1: raise() without actor inherits from triggeringEvent

it('propagates actor from triggering event to raised event', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'actor_propagation',
            'initial' => 'idle',
            'states'  => [
                'idle' => [
                    'on' => [
                        'START' => [
                            'target'  => 'processing',
                            'actions' => RaiseWithoutActorAction::class,
                        ],
                    ],
                ],
                'processing' => [
                    'on' => [
                        'RAISED_EVENT' => [
                            'target'  => 'done',
                            'actions' => CaptureActorAction::class,
                        ],
                    ],
                ],
                'done' => [],
            ],
        ],
    );

    $state = $machine->transition(['type' => 'START', 'actor' => 'user_42']);

    expect($state->context->get('captured_actor'))->toBe('user_42');
});

// endregion

// region Scenario 2: raise() with explicit actor is not overridden

it('does not override explicit actor on raised event', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'explicit_actor',
            'initial' => 'idle',
            'states'  => [
                'idle' => [
                    'on' => [
                        'START' => [
                            'target'  => 'processing',
                            'actions' => RaiseWithExplicitActorAction::class,
                        ],
                    ],
                ],
                'processing' => [
                    'on' => [
                        'RAISED_EVENT' => [
                            'target'  => 'done',
                            'actions' => CaptureActorAction::class,
                        ],
                    ],
                ],
                'done' => [],
            ],
        ],
    );

    $state = $machine->transition(['type' => 'START', 'actor' => 'user_42']);

    expect($state->context->get('captured_actor'))->toBe('explicit_actor');
});

// endregion

// region Scenario 3: no actor anywhere — both stay null

it('keeps actor null when no actor is provided anywhere', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'no_actor',
            'initial' => 'idle',
            'states'  => [
                'idle' => [
                    'on' => [
                        'START' => [
                            'target'  => 'processing',
                            'actions' => RaiseWithoutActorAction::class,
                        ],
                    ],
                ],
                'processing' => [
                    'on' => [
                        'RAISED_EVENT' => [
                            'target'  => 'done',
                            'actions' => CaptureActorAction::class,
                        ],
                    ],
                ],
                'done' => [],
            ],
        ],
    );

    $state = $machine->transition(['type' => 'START']);

    expect($state->context->get('captured_actor'))->toBeNull();
});

// endregion

// region Scenario 4: chain preserves actor through multiple raises

it('preserves actor through chained raises', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'chain_actor',
            'initial' => 'idle',
            'states'  => [
                'idle' => [
                    'on' => [
                        'START' => [
                            'target'  => 'step_1',
                            'actions' => RaiseWithoutActorAction::class,
                        ],
                    ],
                ],
                'step_1' => [
                    'on' => [
                        'RAISED_EVENT' => [
                            'target'  => 'step_2',
                            'actions' => RaiseChainAction::class,
                        ],
                    ],
                ],
                'step_2' => [
                    'on' => [
                        'CHAINED_EVENT' => [
                            'target'  => 'done',
                            'actions' => CaptureActorAction::class,
                        ],
                    ],
                ],
                'done' => [],
            ],
        ],
    );

    $state = $machine->transition(['type' => 'START', 'actor' => 'user_42']);

    expect($state->context->get('chain_actor_1'))->toBe('user_42');
    expect($state->context->get('captured_actor'))->toBe('user_42');
});

// endregion

// region Scenario 5: chain explicit override — explicit actor propagates forward

it('propagates explicit override actor through subsequent raises', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'chain_override',
            'initial' => 'idle',
            'states'  => [
                'idle' => [
                    'on' => [
                        'START' => [
                            'target'  => 'step_1',
                            'actions' => RaiseWithExplicitActorAction::class,
                        ],
                    ],
                ],
                'step_1' => [
                    'on' => [
                        'RAISED_EVENT' => [
                            'target'  => 'step_2',
                            'actions' => RaiseChainAction::class,
                        ],
                    ],
                ],
                'step_2' => [
                    'on' => [
                        'CHAINED_EVENT' => [
                            'target'  => 'done',
                            'actions' => CaptureActorAction::class,
                        ],
                    ],
                ],
                'done' => [],
            ],
        ],
    );

    $state = $machine->transition(['type' => 'START', 'actor' => 'user_42']);

    // The explicit actor from RaiseWithExplicitActorAction ('explicit_actor') should
    // override the triggering event's actor ('user_42') and propagate forward.
    expect($state->context->get('chain_actor_1'))->toBe('explicit_actor');
    expect($state->context->get('captured_actor'))->toBe('explicit_actor');
});

// endregion
