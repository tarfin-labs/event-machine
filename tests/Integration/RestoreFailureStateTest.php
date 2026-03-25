<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\FailingChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateChildMachine;

// ============================================================
// Restore Failure State Tests (MassTransit Cross-Cutting)
// ============================================================

// ─── Test 5: Restore does NOT replay entry actions ─────────────

it('restore from DB does not replay entry actions', function (): void {
    $entryActionCount = 0;

    $configAndBehavior = [
        'config' => [
            'id'      => 'restore_no_replay',
            'initial' => 'idle',
            'context' => [
                'entry_count' => 0,
            ],
            'states' => [
                'idle' => [
                    'on' => ['GO' => 'active'],
                ],
                'active' => [
                    'entry' => 'countEntryAction',
                    'on'    => ['FINISH' => 'done'],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        'behavior' => [
            'actions' => [
                'countEntryAction' => function (ContextManager $ctx) use (&$entryActionCount): void {
                    $entryActionCount++;
                    $ctx->set('entry_count', $ctx->get('entry_count') + 1);
                },
            ],
        ],
    ];

    // Create and transition to 'active' (entry action runs once)
    $machine = Machine::create($configAndBehavior);
    $machine->send(['type' => 'GO']);

    expect($entryActionCount)->toBe(1)
        ->and($machine->state->context->get('entry_count'))->toBe(1);

    $rootEventId = $machine->state->history->first()->root_event_id;

    // Reset counter before restore
    $entryActionCount = 0;

    // Restore from DB — should NOT replay entry actions
    $definition = MachineDefinition::define(
        config: $configAndBehavior['config'],
        behavior: $configAndBehavior['behavior'],
    );
    $restoredMachine = Machine::withDefinition($definition);
    $restoredMachine->start($rootEventId);

    // Entry action should NOT have been called again
    expect($entryActionCount)->toBe(0)
        ->and($restoredMachine->state->matches('active'))->toBeTrue()
        ->and($restoredMachine->state->context->get('entry_count'))->toBe(1);
});

// ─── Test 6: Restore failed state preserves error context ──────

it('machine transitioned to @fail state preserves error context after persist and restore', function (): void {
    $configAndBehavior = [
        'config' => [
            'id'      => 'restore_fail_fidelity',
            'initial' => 'idle',
            'context' => [
                'error_message' => null,
                'error_code'    => null,
            ],
            'states' => [
                'idle' => ['on' => ['GO' => 'processing']],
                'processing' => [
                    'machine' => FailingChildMachine::class,
                    '@done'   => 'completed',
                    '@fail'   => [
                        'target'  => 'failed',
                        'actions' => 'captureFailAction',
                    ],
                ],
                'completed' => ['type' => 'final'],
                'failed'    => ['type' => 'final'],
            ],
        ],
        'behavior' => [
            'actions' => [
                'captureFailAction' => function (ContextManager $ctx, EventBehavior $event): void {
                    $ctx->set('error_message', $event->payload['error_message'] ?? 'unknown');
                    $ctx->set('error_code', 'CHILD_FAILED');
                },
            ],
        ],
    ];

    // Create, trigger failure
    $machine = Machine::create($configAndBehavior);
    $machine->send(['type' => 'GO']);

    expect($machine->state->matches('failed'))->toBeTrue()
        ->and($machine->state->context->get('error_message'))->toBe('Payment gateway down')
        ->and($machine->state->context->get('error_code'))->toBe('CHILD_FAILED');

    // Persist and restore
    $rootEventId = $machine->state->history->first()->root_event_id;

    $definition = MachineDefinition::define(
        config: $configAndBehavior['config'],
        behavior: $configAndBehavior['behavior'],
    );
    $restored = Machine::withDefinition($definition);
    $restored->start($rootEventId);

    // Error context must be preserved exactly
    expect($restored->state->matches('failed'))->toBeTrue()
        ->and($restored->state->context->get('error_message'))->toBe('Payment gateway down')
        ->and($restored->state->context->get('error_code'))->toBe('CHILD_FAILED');
});

// ─── Test 7: Restored machine with completed child does NOT re-send @done ──

it('restored machine with completed child does not re-send @done to parent', function (): void {
    $doneActionCount = 0;

    $configAndBehavior = [
        'config' => [
            'id'      => 'restore_no_renotify',
            'initial' => 'idle',
            'context' => [
                'done_count' => 0,
            ],
            'states' => [
                'idle' => ['on' => ['GO' => 'delegating']],
                'delegating' => [
                    'machine' => ImmediateChildMachine::class,
                    '@done'   => [
                        'target'  => 'completed',
                        'actions' => 'countDoneAction',
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        'behavior' => [
            'actions' => [
                'countDoneAction' => function (ContextManager $ctx) use (&$doneActionCount): void {
                    $doneActionCount++;
                    $ctx->set('done_count', $ctx->get('done_count') + 1);
                },
            ],
        ],
    ];

    // Create and delegate — child completes immediately, @done fires once
    $machine = Machine::create($configAndBehavior);
    $machine->send(['type' => 'GO']);

    expect($doneActionCount)->toBe(1)
        ->and($machine->state->context->get('done_count'))->toBe(1);

    $rootEventId = $machine->state->history->first()->root_event_id;

    // Reset counter
    $doneActionCount = 0;

    // Restore — should NOT re-send @done
    $definition = MachineDefinition::define(
        config: $configAndBehavior['config'],
        behavior: $configAndBehavior['behavior'],
    );
    $restored = Machine::withDefinition($definition);
    $restored->start($rootEventId);

    // @done action should NOT have been called again
    expect($doneActionCount)->toBe(0)
        ->and($restored->state->matches('completed'))->toBeTrue()
        ->and($restored->state->context->get('done_count'))->toBe(1);
});
