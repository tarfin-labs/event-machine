<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

// ============================================================
// Restored Final State
// ============================================================
// A machine that has been transitioned to a final state, persisted,
// and then restored from the database should correctly report that
// it is in a final state and result() should work.

it('restored machine at final state reports final type and result works', function (): void {
    $configAndBehavior = [
        'config' => [
            'id'      => 'restore_final',
            'initial' => 'idle',
            'context' => [
                'amount' => 0,
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'COMPLETE' => [
                            'target'  => 'completed',
                            'actions' => 'setAmountAction',
                        ],
                    ],
                ],
                'completed' => [
                    'type'   => 'final',
                    'output' => function (ContextManager $ctx): array {
                        return ['total' => $ctx->get('amount'), 'status' => 'done'];
                    },
                ],
            ],
        ],
        'behavior' => [
            'actions' => [
                'setAmountAction' => function (ContextManager $ctx, EventBehavior $event): void {
                    $ctx->set('amount', $event->payload['amount'] ?? 100);
                },
            ],
        ],
    ];

    // Create machine (auto-persists), transition to final
    $machine = Machine::create($configAndBehavior);
    $machine->send(['type' => 'COMPLETE', 'payload' => ['amount' => 250]]);

    // Verify machine is at final state
    expect($machine->state->matches('completed'))->toBeTrue();
    expect($machine->state->currentStateDefinition->type)->toBe(StateDefinitionType::FINAL);

    // Get root event ID for restoration
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Restore from DB using a fresh Machine instance
    $definition = MachineDefinition::define(
        config: $configAndBehavior['config'],
        behavior: $configAndBehavior['behavior'],
    );
    $restoredMachine = Machine::withDefinition($definition);
    $restoredMachine->start($rootEventId);

    // Restored machine should be at final state
    expect($restoredMachine->state->matches('completed'))->toBeTrue();
    expect($restoredMachine->state->currentStateDefinition->type)->toBe(StateDefinitionType::FINAL);

    // Context should be preserved
    expect($restoredMachine->state->context->get('amount'))->toBe(250);

    // result() should work on restored machine
    expect($restoredMachine->result())->toBe(['total' => 250, 'status' => 'done']);
});

it('restored machine at final state has matching value array', function (): void {
    $configAndBehavior = [
        'config' => [
            'id'      => 'restore_value',
            'initial' => 'pending',
            'context' => [],
            'states'  => [
                'pending' => [
                    'on' => ['FINISH' => 'done'],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
    ];

    $machine = Machine::create($configAndBehavior);
    $machine->send(['type' => 'FINISH']);

    $originalValue = $machine->state->value;
    $rootEventId   = $machine->state->history->first()->root_event_id;

    // Restore
    $definition = MachineDefinition::define(config: $configAndBehavior['config']);
    $restored   = Machine::withDefinition($definition);
    $restored->start($rootEventId);

    expect($restored->state->value)->toBe($originalValue);
    expect($restored->state->matches('done'))->toBeTrue();
});
