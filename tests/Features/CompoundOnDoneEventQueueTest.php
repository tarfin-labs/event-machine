<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\RaiseResultReadyAction;

// ============================================================
// Event Queue + @always Processing After Compound @done
// ============================================================

it('processes raised events from entry actions after compound @done transition', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'compound_raise_test',
            'initial' => 'idle',
            'context' => ['protocol_result' => null],
            'states'  => [
                'idle' => [
                    'on' => ['START' => 'review'],
                ],
                'review' => [
                    'initial' => 'checking',
                    'states'  => [
                        'checking' => [
                            'on' => ['APPROVE' => 'approved'],
                        ],
                        'approved' => [
                            'type' => 'final',
                        ],
                    ],
                    '@done' => 'evaluating',
                ],
                'evaluating' => [
                    'entry' => 'raiseResultAction',
                    'on'    => [
                        'RESULT_READY' => 'completed',
                    ],
                ],
                'completed' => [
                    'type' => 'final',
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'raiseResultAction' => RaiseResultReadyAction::class,
            ],
        ],
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(event: ['type' => 'START'], state: $state);
    expect($state->value)->toBe(['compound_raise_test.review.checking']);

    $state = $definition->transition(event: ['type' => 'APPROVE'], state: $state);

    // approved (final) → compound @done → evaluating → entry raises RESULT_READY → completed
    expect($state->value)->toBe(['compound_raise_test.completed']);
    expect($state->context->get('protocol_result'))->toBe('decided');
});

it('processes @always transitions after compound @done transition', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'compound_always_test',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['START' => 'review'],
                ],
                'review' => [
                    'initial' => 'checking',
                    'states'  => [
                        'checking' => [
                            'on' => ['APPROVE' => 'approved'],
                        ],
                        'approved' => [
                            'type' => 'final',
                        ],
                    ],
                    '@done' => 'routing',
                ],
                'routing' => [
                    'on' => [
                        '@always' => 'completed',
                    ],
                ],
                'completed' => [
                    'type' => 'final',
                ],
            ],
        ],
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(event: ['type' => 'START'], state: $state);
    $state = $definition->transition(event: ['type' => 'APPROVE'], state: $state);

    // approved (final) → compound @done → routing → @always → completed
    expect($state->value)->toBe(['compound_always_test.completed']);
});
