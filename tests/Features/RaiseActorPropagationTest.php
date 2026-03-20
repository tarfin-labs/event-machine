<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\RaiseActorPropagation\RaiseChainAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\RaiseActorPropagation\CaptureActorAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\RaiseActorPropagation\RaiseWithoutActorAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\RaiseActorPropagation\RaiseOverrideEventAction;
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

// region Scenario 6: consecutive send() calls don't leak actor

it('does not leak actor between consecutive send calls', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'consecutive_send',
            'initial' => 'idle',
            'states'  => [
                'idle' => [
                    'on' => [
                        'FIRST' => [
                            'target'  => 'step_1',
                            'actions' => RaiseWithoutActorAction::class,
                        ],
                    ],
                ],
                'step_1' => [
                    'on' => [
                        'RAISED_EVENT' => [
                            'target'  => 'step_2',
                            'actions' => CaptureActorAction::class,
                        ],
                        'SECOND' => [
                            'target'  => 'step_3',
                            'actions' => RaiseWithoutActorAction::class,
                        ],
                    ],
                ],
                'step_2' => [
                    'on' => [
                        'SECOND' => [
                            'target'  => 'step_3',
                            'actions' => RaiseWithoutActorAction::class,
                        ],
                    ],
                ],
                'step_3' => [
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

    // First send with actor
    $state = $machine->transition(['type' => 'FIRST', 'actor' => 'user_42'], $machine->getInitialState());
    expect($state->context->get('captured_actor'))->toBe('user_42');

    // Second send WITHOUT actor — should NOT inherit 'user_42'
    $state = $machine->transition(['type' => 'SECOND'], $state);
    expect($state->context->get('captured_actor'))->toBeNull();
});

// endregion

// region Scenario 7: timer → raise — no actor propagation (triggeringEvent actor is null)

it('does not propagate actor when triggering event has no actor', function (): void {
    // This simulates timer/lifecycle events: the triggering event exists but has no actor.
    // We test the underlying mechanism: if triggeringEvent->actor() is null, no propagation.
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'null_actor_trigger',
            'initial' => 'idle',
            'states'  => [
                'idle' => [
                    'on' => [
                        'TIMER_FIRE' => [
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

    // Send event without actor (simulates timer/lifecycle event)
    $state = $machine->transition(['type' => 'TIMER_FIRE']);

    expect($state->context->get('captured_actor'))->toBeNull();
});

// endregion

// region Scenario 8: @done → raise — lifecycle event, no actor propagation
// Note: @done/child delegation tests require full async setup. The mechanism is identical
// to scenario 7 — lifecycle events have no actor, so triggeringEvent->actor() is null.
// The child delegation test suite in ChildDelegation/ covers @done behavior.
// We verify the rule: null actor on triggeringEvent → no propagation (covered by scenario 7).
// endregion

// region Scenario 9: event with actor() override preserves override value

it('preserves actor from event class with actor() override', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'actor_override',
            'initial' => 'idle',
            'states'  => [
                'idle' => [
                    'on' => [
                        'START' => [
                            'target'  => 'processing',
                            'actions' => RaiseOverrideEventAction::class,
                        ],
                    ],
                ],
                'processing' => [
                    'on' => [
                        'OVERRIDE_EVENT' => [
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

    // The ActorOverrideEvent overrides actor() to return 'overridden_actor'.
    // This should NOT be overwritten by auto-propagation.
    expect($state->context->get('captured_actor'))->toBe('overridden_actor');
});

// endregion

// region Scenario 10: initial @always → raise — no propagation

it('does not propagate actor when initial state has @always and no triggering event', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'initial_always',
            'initial' => 'routing',
            'context' => ['captured_actor' => 'not_set'],
            'states'  => [
                'routing' => [
                    'entry' => RaiseWithoutActorAction::class,
                    'on'    => [
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

    $state = $machine->getInitialState();

    // Entry action on initial state raises an event. No external event was sent,
    // so triggeringEvent is null → no actor propagation.
    expect($state->context->get('captured_actor'))->toBeNull();
});

// endregion
