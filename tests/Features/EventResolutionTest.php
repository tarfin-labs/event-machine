<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Enums\SourceType;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\StrictEEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\AsdMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Events\EEvent as AsdEEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Events\SEvent as AsdSEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Qwerty\Events\EEvent as QwertyEEvent;

// region Phase 1: initializeEvent() type-string-based resolution

test('same class event passes through without re-instantiation', function (): void {
    $captured = null;

    $definition = MachineDefinition::define(
        config: [
            'initial' => 'idle',
            'states'  => [
                'idle' => [
                    'on' => [
                        AsdEEvent::class => [
                            'target'  => 'done',
                            'actions' => 'capture',
                        ],
                    ],
                ],
                'done' => [],
            ],
        ],
        behavior: [
            'actions' => [
                'capture' => function (ContextManager $context, EventBehavior $event) use (&$captured): void {
                    $captured = $event;
                },
            ],
        ],
    );

    $event = new AsdEEvent(payload: ['key' => 'value']);
    $definition->transition($event);

    expect($captured)->toBeInstanceOf(AsdEEvent::class)
        ->and($captured->payload)->toBe(['key' => 'value']);
});

test('different class with same type is re-instantiated to machine own class', function (): void {
    $captured = null;

    $definition = MachineDefinition::define(
        config: [
            'initial' => 'idle',
            'states'  => [
                'idle' => [
                    'on' => [
                        AsdEEvent::class => [
                            'target'  => 'done',
                            'actions' => 'capture',
                        ],
                    ],
                ],
                'done' => [],
            ],
        ],
        behavior: [
            'actions' => [
                'capture' => function (ContextManager $context, EventBehavior $event) use (&$captured): void {
                    $captured = $event;
                },
            ],
        ],
    );

    $foreignEvent = new QwertyEEvent(payload: ['foreign' => 'data']);
    $definition->transition($foreignEvent);

    // Machine should use its own class (AsdEEvent), not the foreign QwertyEEvent
    expect($captured)->toBeInstanceOf(AsdEEvent::class)
        ->and($captured)->not->toBeInstanceOf(QwertyEEvent::class)
        ->and($captured->type)->toBe('E_EVENT')
        ->and($captured->payload)->toBe(['foreign' => 'data']);
});

test('array event is resolved from registry', function (): void {
    $captured = null;

    $definition = MachineDefinition::define(
        config: [
            'initial' => 'idle',
            'states'  => [
                'idle' => [
                    'on' => [
                        AsdEEvent::class => [
                            'target'  => 'done',
                            'actions' => 'capture',
                        ],
                    ],
                ],
                'done' => [],
            ],
        ],
        behavior: [
            'actions' => [
                'capture' => function (ContextManager $context, EventBehavior $event) use (&$captured): void {
                    $captured = $event;
                },
            ],
        ],
    );

    $definition->transition(['type' => 'E_EVENT', 'payload' => ['arr' => 'data']]);

    expect($captured)->toBeInstanceOf(AsdEEvent::class)
        ->and($captured->payload)->toBe(['arr' => 'data']);
});

test('string event type is resolved correctly via Machine send', function (): void {
    $machine = AsdMachine::create();

    // E_EVENT on stateA is a self-transition (no target) with SleepAction
    // We verify it doesn't throw - the event resolution works for string input
    $state = $machine->send('E_EVENT');

    // Machine stays in stateA (self-transition)
    expect($state->value)->toContain('machine.stateA');
});

test('event not in registry passes through as-is', function (): void {
    $captured = null;

    $definition = MachineDefinition::define(
        config: [
            'initial' => 'idle',
            'states'  => [
                'idle' => [
                    'on' => [
                        'TIMER' => [
                            'target'  => 'active',
                            'actions' => 'capture',
                        ],
                    ],
                ],
                'active' => [],
            ],
        ],
        behavior: [
            'actions' => [
                'capture' => function (ContextManager $context, EventBehavior $event) use (&$captured): void {
                    $captured = $event;
                },
            ],
        ],
    );

    $definition->transition(['type' => 'TIMER']);

    expect($captured->type)->toBe('TIMER');
});

test('string-only config key works without class registration', function (): void {
    $definition = MachineDefinition::define(config: [
        'initial' => 'off',
        'states'  => [
            'off' => [
                'on' => [
                    'TOGGLE' => [
                        'target' => 'on',
                    ],
                ],
            ],
            'on' => [],
        ],
    ]);

    $state = $definition->transition(['type' => 'TOGGLE']);

    expect($state->value)->toContain('machine.on');
});

test('payload is preserved during re-instantiation', function (): void {
    $captured = null;

    $definition = MachineDefinition::define(
        config: [
            'initial' => 'idle',
            'states'  => [
                'idle' => [
                    'on' => [
                        AsdEEvent::class => [
                            'target'  => 'done',
                            'actions' => 'capture',
                        ],
                    ],
                ],
                'done' => [],
            ],
        ],
        behavior: [
            'actions' => [
                'capture' => function (ContextManager $context, EventBehavior $event) use (&$captured): void {
                    $captured = $event;
                },
            ],
        ],
    );

    $foreignEvent = new QwertyEEvent(payload: ['amount' => 100, 'currency' => 'TRY']);
    $definition->transition($foreignEvent);

    expect($captured)->toBeInstanceOf(AsdEEvent::class)
        ->and($captured->payload)->toBe(['amount' => 100, 'currency' => 'TRY']);
});

test('version and source are preserved during re-instantiation', function (): void {
    $captured = null;

    $definition = MachineDefinition::define(
        config: [
            'initial' => 'idle',
            'states'  => [
                'idle' => [
                    'on' => [
                        AsdEEvent::class => [
                            'target'  => 'done',
                            'actions' => 'capture',
                        ],
                    ],
                ],
                'done' => [],
            ],
        ],
        behavior: [
            'actions' => [
                'capture' => function (ContextManager $context, EventBehavior $event) use (&$captured): void {
                    $captured = $event;
                },
            ],
        ],
    );

    $foreignEvent = new QwertyEEvent(
        payload: ['data' => true],
        version: 3,
        source: SourceType::INTERNAL,
    );

    $definition->transition($foreignEvent);

    expect($captured)->toBeInstanceOf(AsdEEvent::class)
        ->and($captured->version)->toBe(3)
        ->and($captured->source)->toBe(SourceType::INTERNAL);
});

test('machine validation class is used after re-instantiation', function (): void {
    // Machine registers StrictEEvent (requires payload.amount)
    $definition = MachineDefinition::define(config: [
        'initial' => 'idle',
        'states'  => [
            'idle' => [
                'on' => [
                    StrictEEvent::class => [
                        'target' => 'done',
                    ],
                ],
            ],
            'done' => [],
        ],
    ]);

    // Send a QwertyEEvent (same type E_EVENT, no validation) without required payload
    $foreignEvent = new QwertyEEvent(payload: []);

    // Machine re-instantiates to StrictEEvent, selfValidate() catches missing amount
    expect(fn () => $definition->transition($foreignEvent))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('hierarchical states resolve events through parent chain', function (): void {
    $captured = null;

    $definition = MachineDefinition::define(
        config: [
            'initial' => 'parent',
            'states'  => [
                'parent' => [
                    'initial' => 'child',
                    'states'  => [
                        'child' => [
                            'on' => [
                                AsdEEvent::class => [
                                    'target'  => 'done',
                                    'actions' => 'capture',
                                ],
                            ],
                        ],
                    ],
                ],
                'done' => [],
            ],
        ],
        behavior: [
            'actions' => [
                'capture' => function (ContextManager $context, EventBehavior $event) use (&$captured): void {
                    $captured = $event;
                },
            ],
        ],
    );

    $foreignEvent = new QwertyEEvent(payload: ['nested' => true]);
    $definition->transition($foreignEvent);

    expect($captured)->toBeInstanceOf(AsdEEvent::class)
        ->and($captured->payload)->toBe(['nested' => true]);
});

test('parallel states resolve foreign events using machine own class', function (): void {
    $captured = null;

    $definition = MachineDefinition::define(
        config: [
            'initial' => 'active',
            'states'  => [
                'active' => [
                    'type'   => 'parallel',
                    'states' => [
                        'regionA' => [
                            'initial' => 'idle',
                            'states'  => [
                                'idle' => [
                                    'on' => [
                                        AsdEEvent::class => [
                                            'target'  => 'working',
                                            'actions' => 'capture',
                                        ],
                                    ],
                                ],
                                'working' => [],
                            ],
                        ],
                        'regionB' => [
                            'initial' => 'waiting',
                            'states'  => [
                                'waiting' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'capture' => function (ContextManager $context, EventBehavior $event) use (&$captured): void {
                    $captured = $event;
                },
            ],
        ],
    );

    $state = $definition->getInitialState();

    // Send a foreign event (QwertyEEvent) to a parallel state machine
    $foreignEvent = new QwertyEEvent(payload: ['parallel' => true]);
    $state        = $definition->transition($foreignEvent, $state);

    // Machine should re-instantiate to its own class
    expect($captured)->toBeInstanceOf(AsdEEvent::class)
        ->and($captured->payload)->toBe(['parallel' => true])
        ->and($state->matches('active.regionA.working'))->toBeTrue()
        ->and($state->matches('active.regionB.waiting'))->toBeTrue();
});

// endregion

// region Phase 2: Public API - getAcceptedEvents() and can()

test('getAcceptedEvents returns all registered events for the machine', function (): void {
    $definition = AsdMachine::definition();

    $allEvents = $definition->getAcceptedEvents();

    expect($allEvents)->toHaveKey('E_EVENT')
        ->and($allEvents)->toHaveKey('S_EVENT')
        ->and($allEvents['E_EVENT'])->toBe(AsdEEvent::class)
        ->and($allEvents['S_EVENT'])->toBe(AsdSEvent::class);
});

test('getAcceptedEvents filtered by state returns only that states events', function (): void {
    $machine = AsdMachine::create();

    // stateA has both E_EVENT and S_EVENT transitions
    $stateAEvents = $machine->getAcceptedEvents();

    expect($stateAEvents)->toHaveKey('E_EVENT')
        ->and($stateAEvents)->toHaveKey('S_EVENT');
});

test('can returns true when current state has transition for event type', function (): void {
    $machine = AsdMachine::create();

    expect($machine->can('E_EVENT'))->toBeTrue();
});

test('can returns false when current state lacks transition for event type', function (): void {
    $machine = AsdMachine::create();

    expect($machine->can('NON_EXISTENT_EVENT'))->toBeFalse();
});

test('can accepts EventBehavior class string and resolves type', function (): void {
    $machine = AsdMachine::create();

    expect($machine->can(AsdEEvent::class))->toBeTrue()
        ->and($machine->can(AsdSEvent::class))->toBeTrue();
});

test('can accepts EventBehavior instance and uses its type', function (): void {
    $machine = AsdMachine::create();
    $event   = new AsdEEvent();

    expect($machine->can($event))->toBeTrue();
});

// endregion
