<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\EventProcessingOrder\Actions\FinalEntryAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\EventProcessingOrder\Actions\EntryActionSimple;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\EventProcessingOrder\Actions\NextTransitionAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\EventProcessingOrder\Actions\TransitionActionWithRaise;

test('entry actions execute before raised events', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'test',
            'initial' => 'A',
            'context' => [
                'executionOrder' => [],
            ],
            'states' => [
                'A' => [
                    'on' => [
                        'GO' => [
                            'target'  => 'B',
                            'actions' => TransitionActionWithRaise::class,
                        ],
                    ],
                ],
                'B' => [
                    'entry' => EntryActionSimple::class,
                    'on'    => [
                        'NEXT' => [
                            'target'  => 'C',
                            'actions' => NextTransitionAction::class,
                        ],
                    ],
                ],
                'C' => [
                    'entry' => FinalEntryAction::class,
                ],
            ],
        ],
    );

    $state = $machine->transition(event: ['type' => 'GO']);

    expect($state->context->get('executionOrder'))->toBe([
        'transition_action',    // 1. Transition action runs first
        'B_entry',              // 2. Target state entry runs second (before raised event!)
        'next_transition',      // 3. Raised event's transition action runs third
        'C_entry',              // 4. Final state entry runs last
    ]);

    expect($state->matches('C'))->toBeTrue();
});
