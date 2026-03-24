<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;

test('guard evaluation does not mutate context when guard returns false', function (): void {
    // 1. Arrange — machine with two guarded transitions on CHECK event
    // Guard 1 returns false but has access to context (could mutate it)
    // Guard 2 returns true, and its action sets counter to 1
    $machine = Machine::create([
        'config' => [
            'initial' => 'idle',
            'context' => [
                'counter' => 0,
                'checked' => false,
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'CHECK' => [
                            [
                                'target' => 'rejected',
                                'guards' => 'failingGuardThatTouchesContext',
                            ],
                            [
                                'target'  => 'accepted',
                                'guards'  => 'passingGuard',
                                'actions' => 'setCounterAction',
                            ],
                        ],
                    ],
                ],
                'rejected' => [],
                'accepted' => [],
            ],
        ],
        'behavior' => [
            'guards' => [
                'failingGuardThatTouchesContext' => function (ContextManager $context): bool {
                    // This guard has access to context and COULD mutate it,
                    // but since it returns false, any mutation should not persist.
                    // We deliberately write to context here to verify purity:
                    $context->set('checked', true);

                    return false;
                },
                'passingGuard' => function (): bool {
                    return true;
                },
            ],
            'actions' => [
                'setCounterAction' => function (ContextManager $context): void {
                    $context->set('counter', 1);
                },
            ],
        ],
    ]);

    // 2. Act — send CHECK event; guard 1 fails, guard 2 passes
    $state = $machine->send(['type' => 'CHECK']);

    // 3. Assert
    // Machine should be in 'accepted' (second transition won)
    expect($state->matches('accepted'))->toBeTrue()
        // Counter should be 1 (set by the winning transition's action)
        ->and($state->context->get('counter'))->toBe(1)
        // 'checked' should still be false — the failing guard's mutation must not persist
        ->and($state->context->get('checked'))->toBeFalse();
});
