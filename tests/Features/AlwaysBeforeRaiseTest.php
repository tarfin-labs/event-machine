<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysBeforeRaise\Actions\LogEntryBAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysBeforeRaise\Actions\RaiseEventAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysBeforeRaise\Actions\RaiseNotifyAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysBeforeRaise\Actions\LogRaisedInBAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysBeforeRaise\Actions\LogWrongPathAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysBeforeRaise\Actions\LogEntryBTraceAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysBeforeRaise\Actions\LogNotifyFromAAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysBeforeRaise\Actions\LogNotifyFromBAction;

test('@always transitions are evaluated before raised events', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'always_before_raise',
            'initial' => 'idle',
            'context' => [
                'execution_order' => [],
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'GO' => 'A',
                    ],
                ],
                'A' => [
                    'entry' => RaiseNotifyAction::class,
                    'on'    => [
                        '@always' => 'B',
                        'NOTIFY'  => [
                            'target'  => 'notified_from_A',
                            'actions' => LogNotifyFromAAction::class,
                        ],
                    ],
                ],
                'B' => [
                    'entry' => LogEntryBAction::class,
                    'on'    => [
                        'NOTIFY' => [
                            'target'  => 'notified_from_B',
                            'actions' => LogNotifyFromBAction::class,
                        ],
                    ],
                ],
                'notified_from_A' => [],
                'notified_from_B' => [],
            ],
        ],
    );

    $state = $machine->transition(event: ['type' => 'GO']);

    // @always fires first, moving machine from A to B.
    // Then the raised NOTIFY event is processed from B (not A).
    expect($state->matches('notified_from_B'))->toBeTrue();

    expect($state->context->get('execution_order'))->toBe([
        'A_entry_raise_NOTIFY',   // 1. Entry action on A runs and raises NOTIFY
        'B_entry',                // 2. @always fires: machine moves to B, B's entry runs
        'NOTIFY_handled_in_B',    // 3. Raised NOTIFY is processed from B (not A)
    ]);
});

test('@always with guard true takes priority over raised events', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'always_guard_before_raise',
            'initial' => 'idle',
            'context' => [
                'trace' => [],
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'GO' => 'A',
                    ],
                ],
                'A' => [
                    'entry' => RaiseEventAction::class,
                    'on'    => [
                        '@always' => [
                            'target' => 'B',
                            'guards' => 'alwaysTrueGuard',
                        ],
                        'RAISED' => [
                            'target'  => 'wrong_target',
                            'actions' => LogWrongPathAction::class,
                        ],
                    ],
                ],
                'B' => [
                    'entry' => LogEntryBTraceAction::class,
                    'on'    => [
                        'RAISED' => [
                            'target'  => 'C',
                            'actions' => LogRaisedInBAction::class,
                        ],
                    ],
                ],
                'wrong_target' => [],
                'C'            => [],
            ],
        ],
        behavior: [
            'guards' => [
                'alwaysTrueGuard' => fn (): bool => true,
            ],
        ],
    );

    $state = $machine->transition(event: ['type' => 'GO']);

    // Machine should end in C (B handled RAISED), never in wrong_target
    expect($state->matches('C'))->toBeTrue();

    expect($state->context->get('trace'))->toBe([
        'A_entry',       // 1. Entry action raises RAISED event
        'B_entry',       // 2. @always (guard=true) fires, moves to B
        'RAISED_in_B',   // 3. Raised event processed from B
    ]);
});
