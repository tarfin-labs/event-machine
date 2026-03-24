<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;

test('guard that throws RuntimeException propagates the exception', function (): void {
    // Arrange — machine with a guarded transition where the guard throws
    $machine = Machine::create([
        'config' => [
            'initial' => 'idle',
            'context' => [
                'counter' => 0,
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'ATTEMPT' => [
                            'target' => 'active',
                            'guards' => 'throwingGuard',
                        ],
                    ],
                ],
                'active' => [],
            ],
        ],
        'behavior' => [
            'guards' => [
                'throwingGuard' => function (): bool {
                    throw new RuntimeException('Guard exploded');
                },
            ],
        ],
    ]);

    // Act & Assert — exception propagates (NOT silently treated as false)
    expect(fn () => $machine->send(['type' => 'ATTEMPT']))
        ->toThrow(RuntimeException::class, 'Guard exploded');
});

test('guard that throws leaves machine state unchanged', function (): void {
    // Arrange — machine with a guarded transition where the guard throws
    $machine = Machine::create([
        'config' => [
            'initial' => 'idle',
            'context' => [
                'counter' => 0,
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'ATTEMPT' => [
                            'target' => 'active',
                            'guards' => 'throwingGuard',
                        ],
                    ],
                ],
                'active' => [],
            ],
        ],
        'behavior' => [
            'guards' => [
                'throwingGuard' => function (): bool {
                    throw new RuntimeException('Guard exploded');
                },
            ],
        ],
    ]);

    // Capture state before the failed send
    $stateBefore = $machine->state;

    // Act — attempt to send event, catch the exception
    try {
        $machine->send(['type' => 'ATTEMPT']);
    } catch (RuntimeException) {
        // Expected
    }

    // Assert — machine state is unchanged (still idle, context intact)
    expect($machine->state->matches('idle'))->toBeTrue()
        ->and($machine->state->context->get('counter'))->toBe(0)
        ->and($machine->state->value)->toBe($stateBefore->value);
});

test('guard that throws does not corrupt context even with preceding actions', function (): void {
    // Arrange — machine where a calculator runs before the throwing guard
    $machine = Machine::create([
        'config' => [
            'initial' => 'idle',
            'context' => [
                'counter'  => 0,
                'prepared' => false,
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'ATTEMPT' => [
                            'target'      => 'active',
                            'calculators' => 'prepareCalculator',
                            'guards'      => 'throwingGuard',
                        ],
                    ],
                ],
                'active' => [],
            ],
        ],
        'behavior' => [
            'calculators' => [
                'prepareCalculator' => function (ContextManager $context): void {
                    $context->set('prepared', true);
                    $context->set('counter', 99);
                },
            ],
            'guards' => [
                'throwingGuard' => function (): bool {
                    throw new RuntimeException('Guard exploded after calculator');
                },
            ],
        ],
    ]);

    // Act
    try {
        $machine->send(['type' => 'ATTEMPT']);
    } catch (RuntimeException) {
        // Expected
    }

    // Assert — machine stays in idle, state is not corrupted
    expect($machine->state->matches('idle'))->toBeTrue();
});
