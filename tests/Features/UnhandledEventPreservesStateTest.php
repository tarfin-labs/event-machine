<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\NoTransitionDefinitionFoundException;

// ============================================================
// Unhandled Event Preserves State
// ============================================================
// When an event is sent that the current state does not handle,
// the machine must throw an exception and the state must remain
// completely unchanged — no context mutation, no side effects.

test('unhandled event throws exception and preserves state', function (): void {
    $sideEffectRan = false;

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'unhandled_test',
            'initial' => 'idle',
            'context' => [
                'counter' => 0,
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'START' => [
                            'target'  => 'active',
                            'actions' => 'incrementAction',
                        ],
                    ],
                ],
                'active' => [
                    'entry' => 'sideEffectAction',
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'incrementAction' => function (ContextManager $ctx) use (&$sideEffectRan): void {
                    $ctx->set('counter', $ctx->get('counter') + 1);
                    $sideEffectRan = true;
                },
                'sideEffectAction' => function (ContextManager $ctx) use (&$sideEffectRan): void {
                    $sideEffectRan = true;
                },
            ],
        ],
    );

    $state = $definition->getInitialState();

    // Capture original state for comparison
    $originalValue   = $state->value;
    $originalContext  = $state->context->toArray();
    $originalHistory = $state->history->toArray();

    // Send an event that idle does not handle
    expect(fn () => $definition->transition(['type' => 'UNKNOWN_EVENT'], $state))
        ->toThrow(NoTransitionDefinitionFoundException::class);

    // State must be completely unchanged
    expect($state->value)->toBe($originalValue);
    expect($state->context->toArray())->toBe($originalContext);
    expect($sideEffectRan)->toBeFalse();
});

test('unhandled event does not mutate context values', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'ctx_preserve',
            'initial' => 'waiting',
            'context' => [
                'name'  => 'original',
                'items' => [1, 2, 3],
            ],
            'states' => [
                'waiting' => [
                    'on' => [
                        'PROCEED' => 'done',
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
    );

    $state = $definition->getInitialState();

    expect(fn () => $definition->transition(['type' => 'INVALID'], $state))
        ->toThrow(NoTransitionDefinitionFoundException::class);

    // Context preserved exactly
    expect($state->context->get('name'))->toBe('original');
    expect($state->context->get('items'))->toBe([1, 2, 3]);
    expect($state->matches('waiting'))->toBeTrue();
});

test('unhandled event on Machine::create preserves machine state', function (): void {
    $machine = Machine::create([
        'config' => [
            'id'      => 'machine_unhandled',
            'initial' => 'idle',
            'context' => ['count' => 42],
            'states'  => [
                'idle' => [
                    'on' => [
                        'GO' => 'running',
                    ],
                ],
                'running' => [],
            ],
        ],
    ]);

    $stateBefore = $machine->state;

    expect(fn () => $machine->send(['type' => 'NONEXISTENT']))
        ->toThrow(NoTransitionDefinitionFoundException::class);

    // Machine state must not have changed
    expect($machine->state->matches('idle'))->toBeTrue();
    expect($machine->state->context->get('count'))->toBe(42);
});
