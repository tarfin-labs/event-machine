<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Analysis\PathType;
use Tarfinlabs\EventMachine\Analysis\PathEnumerator;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AbcMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Compound\ConditionalCompoundOnDoneMachine;

test('AbcMachine enumerates 1 DEAD_END path', function (): void {
    $definition = AbcMachine::definition();
    $enumerator = new PathEnumerator($definition);
    $result     = $enumerator->enumerate();

    // AbcMachine: initial=state_b → @always(unguarded) → state_c (no transitions, not FINAL)
    expect($result->paths)->toHaveCount(1)
        ->and($result->deadEndPaths())->toHaveCount(1)
        ->and($result->paths[0]->type)->toBe(PathType::DEAD_END);
});

test('compound @done continuation follows parent onDoneTransition from FINAL child', function (): void {
    // Build a minimal machine with compound @done: inner starts at FINAL, parent has @done → target
    $definition = MachineDefinition::define(config: [
        'id'      => 'compound_done_test',
        'initial' => 'wrapper',
        'states'  => [
            'wrapper' => [
                '@done'   => 'completed',
                'initial' => 'inner_done',
                'states'  => [
                    'inner_done' => ['type' => 'final'],
                ],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    $enumerator = new PathEnumerator($definition);
    $result     = $enumerator->enumerate();

    // inner_done (FINAL) → compound @done → completed (FINAL)
    expect($result->paths)->toHaveCount(1)
        ->and($result->happyPaths())->toHaveCount(1)
        ->and($result->paths[0]->terminalStateId)->toContain('completed');
});

// Full ConditionalCompoundOnDoneMachine test — requires transition enumeration (handleAtomic).
// Added after implement-transition-enumeration task.
test('ConditionalCompoundOnDoneMachine enumerates 2 HAPPY paths via compound @done', function (): void {
    $definition = ConditionalCompoundOnDoneMachine::definition();
    $enumerator = new PathEnumerator($definition);
    $result     = $enumerator->enumerate();

    expect($result->happyPaths())->toHaveCount(2);
})->skip('Requires handleAtomic transition enumeration — enabled after implement-transition-enumeration');
