<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Locks\MachineLockManager;
use Tarfinlabs\EventMachine\Exceptions\MachineAlreadyRunningException;

// ═══════════════════════════════════════════════════════════════
//  Bead 1: Lock rejection leaves machine state and context
//  completely unchanged.
// ═══════════════════════════════════════════════════════════════

it('throws MachineAlreadyRunningException when another process holds the lock', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = Machine::create([
        'config' => [
            'id'      => 'lock_rejection',
            'initial' => 'idle',
            'context' => [
                'counter' => 0,
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'GO' => [
                            'target'  => 'active',
                            'actions' => 'incrementAction',
                        ],
                    ],
                ],
                'active' => [],
            ],
        ],
        'behavior' => [
            'actions' => [
                'incrementAction' => function (ContextManager $context): void {
                    $context->set('counter', $context->get('counter') + 1);
                },
            ],
        ],
    ]);

    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Capture state before the contested send
    $stateBefore   = $machine->state->value;
    $contextBefore = $machine->state->context->get('counter');

    // Simulate another process holding the lock
    $holdLock = MachineLockManager::acquire(
        rootEventId: $rootEventId,
        timeout: 0,
        ttl: 60,
        context: 'other_process',
    );

    try {
        // Restore machine and attempt to send event while lock is held
        $contestedMachine = Machine::create(state: $rootEventId);

        expect(fn () => $contestedMachine->send(['type' => 'GO']))
            ->toThrow(MachineAlreadyRunningException::class);
    } finally {
        $holdLock->release();
    }

    // Restore machine and verify state is completely unchanged
    $afterMachine = Machine::create(state: $rootEventId);

    expect($afterMachine->state->value)->toBe($stateBefore)
        ->and($afterMachine->state->context->get('counter'))->toBe($contextBefore);
});

it('preserves context data exactly after lock rejection', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = Machine::create([
        'config' => [
            'id'      => 'lock_rejection_ctx',
            'initial' => 'idle',
            'context' => [
                'name'   => 'original',
                'amount' => 42,
                'tags'   => ['a', 'b'],
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'MODIFY' => [
                            'target'  => 'modified',
                            'actions' => 'modifyContextAction',
                        ],
                    ],
                ],
                'modified' => [],
            ],
        ],
        'behavior' => [
            'actions' => [
                'modifyContextAction' => function (ContextManager $context): void {
                    $context->set('name', 'changed');
                    $context->set('amount', 999);
                    $context->set('tags', ['x']);
                },
            ],
        ],
    ]);

    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Hold the lock
    $holdLock = MachineLockManager::acquire(
        rootEventId: $rootEventId,
        timeout: 0,
        ttl: 60,
        context: 'blocker',
    );

    try {
        $contestedMachine = Machine::create(state: $rootEventId);

        expect(fn () => $contestedMachine->send(['type' => 'MODIFY']))
            ->toThrow(MachineAlreadyRunningException::class);
    } finally {
        $holdLock->release();
    }

    // Context should be completely unchanged
    $afterMachine = Machine::create(state: $rootEventId);
    expect($afterMachine->state->context->get('name'))->toBe('original')
        ->and($afterMachine->state->context->get('amount'))->toBe(42)
        ->and($afterMachine->state->context->get('tags'))->toBe(['a', 'b'])
        ->and($afterMachine->state->matches('idle'))->toBeTrue();
});

afterEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
});
