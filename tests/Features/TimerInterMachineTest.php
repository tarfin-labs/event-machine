<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Tarfinlabs\EventMachine\Support\Timer;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\SimpleChildMachine;

// ─── Timer on delegation state ──────────────────────────────────

it('after timer on delegation state fires when child is still running', function (): void {
    Bus::fake();

    $machine = MachineDefinition::define(
        config: [
            'id'      => 'timer_delegation',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['START' => 'processing'],
                ],
                'processing' => [
                    'machine' => SimpleChildMachine::class,
                    '@done'   => 'completed',
                    'on'      => [
                        'FORCE_CANCEL' => ['target' => 'timed_out', 'after' => Timer::days(7)],
                    ],
                ],
                'completed' => ['type' => 'final'],
                'timed_out' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    // Verify: the processing state has a timer definition on FORCE_CANCEL transition
    $processingState = $machine->idMap['timer_delegation.processing'];
    $forceCancel     = $processingState->transitionDefinitions['FORCE_CANCEL'];

    expect($forceCancel->timerDefinition)->not->toBeNull()
        ->and($forceCancel->timerDefinition->isAfter())->toBeTrue()
        ->and($forceCancel->timerDefinition->delaySeconds)->toBe(604800);
});

it('after timer coexists with @timeout on delegation state', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'timer_timeout_coexist',
            'initial' => 'processing',
            'context' => [],
            'states'  => [
                'processing' => [
                    'machine'  => SimpleChildMachine::class,
                    '@done'    => 'completed',
                    '@timeout' => ['target' => 'child_timed_out', 'after' => 300],
                    'on'       => [
                        'ALERT' => ['actions' => 'alertAction', 'after' => Timer::days(1)],
                    ],
                ],
                'completed'       => ['type' => 'final'],
                'child_timed_out' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
            'actions' => [
                'alertAction' => function (): void {},
            ],
        ],
    );

    $processingState = $machine->idMap['timer_timeout_coexist.processing'];

    // @timeout transition exists
    expect($processingState->onTimeoutTransition)->not->toBeNull();

    // after timer on ALERT transition exists
    $alertTransition = $processingState->transitionDefinitions['ALERT'];
    expect($alertTransition->timerDefinition)->not->toBeNull()
        ->and($alertTransition->timerDefinition->isAfter())->toBeTrue()
        ->and($alertTransition->timerDefinition->delaySeconds)->toBe(86400);
});

it('timer event can be triggered manually (external send)', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'manual_timer',
            'initial' => 'waiting',
            'context' => [],
            'states'  => [
                'waiting' => [
                    'on' => [
                        'TIMEOUT' => ['target' => 'expired', 'after' => Timer::days(7)],
                    ],
                ],
                'expired' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    $state = $machine->getInitialState();

    // Manually send the timer event — should work even without the sweep
    $state = $machine->transition(event: ['type' => 'TIMEOUT'], state: $state);

    expect($state->value)->toBe(['manual_timer.expired']);
});

it('every timer with actions stays in same state', function (): void {
    $actionRan = false;

    $machine = MachineDefinition::define(
        config: [
            'id'      => 'every_action',
            'initial' => 'active',
            'context' => ['count' => 0],
            'states'  => [
                'active' => [
                    'on' => [
                        'HEARTBEAT' => ['actions' => 'heartbeatAction', 'every' => Timer::hours(1)],
                        'STOP'      => 'stopped',
                    ],
                ],
                'stopped' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
            'actions' => [
                'heartbeatAction' => function (ContextManager $ctx) use (&$actionRan): void {
                    $ctx->set('count', $ctx->get('count') + 1);
                    $actionRan = true;
                },
            ],
        ],
    );

    $state = $machine->getInitialState();

    // Send heartbeat — should run action but stay in active state
    $state = $machine->transition(event: ['type' => 'HEARTBEAT'], state: $state);

    expect($state->value)->toBe(['every_action.active'])
        ->and($state->context->get('count'))->toBe(1)
        ->and($actionRan)->toBeTrue();
});
