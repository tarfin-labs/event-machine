<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

test('parallel @done fires only when ALL regions reach final state', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'done_timing',
        'initial' => 'checking',
        'states'  => [
            'checking' => [
                'type'   => 'parallel',
                '@done'  => 'completed',
                'states' => [
                    'region_a' => [
                        'initial' => 'a_processing',
                        'states'  => [
                            'a_processing' => [
                                'on' => [
                                    'A_COMPLETE' => 'a_done',
                                ],
                            ],
                            'a_done' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'b_processing',
                        'states'  => [
                            'b_processing' => [
                                'on' => [
                                    'B_COMPLETE' => 'b_done',
                                ],
                            ],
                            'b_done' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    // Both regions start in their processing states
    expect($state->matches('checking.region_a.a_processing'))->toBeTrue();
    expect($state->matches('checking.region_b.b_processing'))->toBeTrue();

    // Send A_COMPLETE — only region_a reaches final
    $state = $definition->transition(['type' => 'A_COMPLETE'], $state);

    // Machine must STILL be in 'checking' — region_b is not final yet
    expect($state->matches('checking.region_a.a_done'))->toBeTrue();
    expect($state->matches('checking.region_b.b_processing'))->toBeTrue();
    expect($state->matches('completed'))->toBeFalse(
        '@done must NOT fire when only one region is final'
    );

    // Send B_COMPLETE — now both regions are final
    $state = $definition->transition(['type' => 'B_COMPLETE'], $state);

    // Machine should transition to 'completed' via @done
    expect($state->matches('completed'))->toBeTrue(
        '@done must fire when ALL regions reach final state'
    );
    expect($state->currentStateDefinition->type)->toBe(StateDefinitionType::FINAL);
});
