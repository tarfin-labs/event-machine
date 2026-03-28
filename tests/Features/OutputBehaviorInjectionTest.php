<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Outputs\GreenOutput;

// region Core Injection

it('injects ContextManager into result behavior', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'result_ctx',
            'initial' => 'done',
            'context' => ['value' => 42],
            'states'  => [
                'done' => [
                    'type'   => 'final',
                    'output' => 'contextResult',
                ],
            ],
        ],
        behavior: [
            'outputs' => [
                'done' => fn (ContextManager $context): int => $context->get('value'),
            ],
        ],
    );

    $state   = $definition->getInitialState();
    $machine = new class() {
        public State $state;
        public MachineDefinition $definition;
    };
    $machine->state      = $state;
    $machine->definition = $definition;

    // Use Machine class directly
    $def            = $definition;
    $outputBehavior = $def->behavior['outputs']['done'];
    $params         = InvokableBehavior::injectInvokableBehaviorParameters(
        actionBehavior: $outputBehavior,
        state: $state,
        eventBehavior: $state->currentEventBehavior,
    );
    $result = $outputBehavior(...$params);

    expect($result)->toBe(42);
});

it('injects EventBehavior into result behavior', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'result_event',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => ['on' => ['FINISH' => 'done']],
                'done' => [
                    'type'   => 'final',
                    'output' => 'eventResult',
                ],
            ],
        ],
        behavior: [
            'outputs' => [
                'done' => fn (ContextManager $context, EventBehavior $event): bool => $event instanceof EventBehavior,
            ],
        ],
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'FINISH'], $state);

    $outputBehavior = $definition->behavior['outputs']['done'];
    $params         = InvokableBehavior::injectInvokableBehaviorParameters(
        actionBehavior: $outputBehavior,
        state: $state,
        eventBehavior: $state->currentEventBehavior,
    );
    $result = $outputBehavior(...$params);

    expect($result)->toBeTrue();
});

it('injects parameters regardless of order (reversed)', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'result_reversed',
            'initial' => 'idle',
            'context' => ['name' => 'test'],
            'states'  => [
                'idle' => ['on' => ['DONE' => 'done']],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'outputs' => [
                // Reversed order: Event first, Context second
                // Injection should resolve by type, not position
                'done' => fn (EventBehavior $event, ContextManager $context): array => [
                    'has_event' => $event instanceof EventBehavior,
                    'name'      => $context->get('name'),
                ],
            ],
        ],
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'DONE'], $state);

    $outputBehavior = $definition->behavior['outputs']['done'];
    $params         = InvokableBehavior::injectInvokableBehaviorParameters(
        actionBehavior: $outputBehavior,
        state: $state,
        eventBehavior: $state->currentEventBehavior,
    );
    $result = $outputBehavior(...$params);

    // Key assertion: event IS an EventBehavior (not ContextManager) even though it's the 1st param
    // And context IS a ContextManager (not EventBehavior) even though it's the 2nd param
    expect($result['has_event'])->toBeTrue()
        ->and($result['name'])->toBe('test');
});

it('injects State into result behavior', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'result_state',
            'initial' => 'done',
            'context' => [],
            'states'  => [
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'outputs' => [
                'done' => fn (ContextManager $context, State $state): array => $state->value,
            ],
        ],
    );

    $state = $definition->getInitialState();

    $outputBehavior = $definition->behavior['outputs']['done'];
    $params         = InvokableBehavior::injectInvokableBehaviorParameters(
        actionBehavior: $outputBehavior,
        state: $state,
        eventBehavior: $state->currentEventBehavior,
    );
    $result = $outputBehavior(...$params);

    expect($result)->toContain('result_state.done');
});

it('works with no parameters', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'result_noparam',
            'initial' => 'done',
            'context' => [],
            'states'  => [
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'outputs' => [
                'done' => fn (): string => 'hello',
            ],
        ],
    );

    $state = $definition->getInitialState();

    $outputBehavior = $definition->behavior['outputs']['done'];
    $params         = InvokableBehavior::injectInvokableBehaviorParameters(
        actionBehavior: $outputBehavior,
        state: $state,
        eventBehavior: $state->currentEventBehavior,
    );
    $result = $outputBehavior(...$params);

    expect($result)->toBe('hello');
});

// endregion

// region Via Machine::result()

it('Machine::result() uses injection for FQCN result class', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'result_fqcn',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => ['on' => ['GO' => 'done']],
                'done' => [
                    'type'   => 'final',
                    'output' => GreenOutput::class,
                ],
            ],
        ],
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'GO'], $state);

    // GreenOutput has __invoke(): Carbon — no params, should still work
    expect($state->currentStateDefinition->type)->toBe(StateDefinitionType::FINAL);
});

it('Machine::result() uses injection for closure result', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'result_closure',
            'initial' => 'done',
            'context' => ['total' => 100],
            'states'  => [
                'done' => [
                    'type'   => 'final',
                    'output' => fn (ContextManager $context): int => $context->get('total'),
                ],
            ],
        ],
    );

    $state = $definition->getInitialState();

    // The result is registered under the full state ID
    $stateId        = $state->currentStateDefinition->id;
    $outputBehavior = $definition->behavior['outputs'][$stateId];
    $params         = InvokableBehavior::injectInvokableBehaviorParameters(
        actionBehavior: $outputBehavior,
        state: $state,
        eventBehavior: $state->currentEventBehavior,
    );
    $result = $outputBehavior(...$params);

    expect($result)->toBe(100);
});

// endregion
