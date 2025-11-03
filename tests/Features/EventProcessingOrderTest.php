<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Xyz\XyzMachine;
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

test('XyzMachine demonstrates entry actions before raised events', function (): void {
    $machine = XyzMachine::create();

    // XyzMachine starts at #a with entry action that:
    // 1. Appends 'x' to context.value
    // 2. Raises @x event
    // Then transitions to #x with entry action that:
    // 1. Appends 'y' to context.value
    // 2. Raises Y_EVENT
    // Then transitions to #y with entry action that:
    // 1. Appends 'z' to context.value
    // 2. Raises @z event
    // Finally transitions to #z

    expect($machine->state->matches('#z'))->toBeTrue();

    // Context shows all entry actions completed: 'x' + 'y' + 'z'
    // If raised events interrupted entry actions, we'd see different order
    expect($machine->state->context->value)->toBe('xyz');

    $history = $machine->state->history->pluck('type')->toArray();

    // Pattern should be: entry.start -> action -> event.raised -> entry.finish -> [event processes]
    $entryAStartIndex  = array_search('xyz.state.#a.entry.start', $history, true);
    $entryAFinishIndex = array_search('xyz.state.#a.entry.finish', $history, true);
    $xEventIndex       = array_search('@x', $history, true);

    // Entry finish should come before event processes
    expect($entryAFinishIndex)->toBeLessThan($xEventIndex);
    expect($entryAStartIndex)->toBeLessThan($entryAFinishIndex);
});

test('multiple entry actions complete in order', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'test',
            'initial' => 'idle',
            'context' => [
                'executionOrder' => [],
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'START' => 'loading',
                    ],
                ],
                'loading' => [
                    'entry' => ['firstEntry', 'secondEntry', 'thirdEntry'],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'firstEntry' => function (ContextManager $context): void {
                    $order   = $context->get('executionOrder');
                    $order[] = 'first_entry';
                    $context->set('executionOrder', $order);
                },
                'secondEntry' => function (ContextManager $context): void {
                    $order   = $context->get('executionOrder');
                    $order[] = 'second_entry';
                    $context->set('executionOrder', $order);
                },
                'thirdEntry' => function (ContextManager $context): void {
                    $order   = $context->get('executionOrder');
                    $order[] = 'third_entry';
                    $context->set('executionOrder', $order);
                },
            ],
        ],
    );

    $state = $machine->transition(event: ['type' => 'START']);

    expect($state->context->get('executionOrder'))->toBe([
        'first_entry',
        'second_entry',
        'third_entry',
    ]);
});

test('context changes are visible across actions', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'test',
            'initial' => 'idle',
            'context' => [
                'counter' => 0,
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'INCREMENT' => [
                            'actions' => 'incrementCounter',
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'incrementCounter' => function (ContextManager $context): void {
                    $context->set('counter', $context->get('counter') + 1);
                },
            ],
        ],
    );

    $state = $machine->transition(event: ['type' => 'INCREMENT']);
    expect($state->context->get('counter'))->toBe(1);

    $state = $machine->transition(event: ['type' => 'INCREMENT'], state: $state);
    expect($state->context->get('counter'))->toBe(2);

    $state = $machine->transition(event: ['type' => 'INCREMENT'], state: $state);
    expect($state->context->get('counter'))->toBe(3);
});

test('raised events from XyzMachine process after each entry completes', function (): void {
    $machine = XyzMachine::create();

    $history = $machine->state->history->pluck('type')->toArray();

    // State #a entry should complete before @x event processes
    $aEntryStart  = array_search('xyz.state.#a.entry.start', $history, true);
    $aEntryFinish = array_search('xyz.state.#a.entry.finish', $history, true);
    $xEventRaised = array_search('xyz.event.@x.raised', $history, true);
    $xEvent       = array_search('@x', $history, true);

    expect($aEntryStart)->toBeLessThan($xEventRaised);
    expect($xEventRaised)->toBeLessThan($aEntryFinish);
    expect($aEntryFinish)->toBeLessThan($xEvent);

    // State #x entry should complete before Y_EVENT processes
    $xEntryStart  = array_search('xyz.state.#x.entry.start', $history, true);
    $xEntryFinish = array_search('xyz.state.#x.entry.finish', $history, true);
    $yEventRaised = array_search('xyz.event.Y_EVENT.raised', $history, true);
    $yEvent       = array_search('Y_EVENT', $history, true);

    expect($xEntryStart)->toBeLessThan($yEventRaised);
    expect($yEventRaised)->toBeLessThan($xEntryFinish);
    expect($xEntryFinish)->toBeLessThan($yEvent);

    // State #y entry should complete before @z event processes
    $yEntryStart  = array_search('xyz.state.#y.entry.start', $history, true);
    $yEntryFinish = array_search('xyz.state.#y.entry.finish', $history, true);
    $zEventRaised = array_search('xyz.event.@z.raised', $history, true);
    $zEvent       = array_search('@z', $history, true);

    expect($yEntryStart)->toBeLessThan($zEventRaised);
    expect($zEventRaised)->toBeLessThan($yEntryFinish);
    expect($yEntryFinish)->toBeLessThan($zEvent);
});
