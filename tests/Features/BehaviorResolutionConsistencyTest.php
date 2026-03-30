<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Behavior\OutputBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\BehaviorNotFoundException;

// ═══════════════════════════════════════════════════════════════
//  Shared Test Stubs
// ═══════════════════════════════════════════════════════════════

class DoubleAmountOutput extends OutputBehavior
{
    public function __invoke(ContextManager $ctx): array
    {
        return ['doubled' => $ctx->get('amount') * 2];
    }
}

// ═══════════════════════════════════════════════════════════════
//  resolveOutputKey() — Unit Tests
// ═══════════════════════════════════════════════════════════════

it('resolveOutputKey resolves FQCN class via container', function (): void {
    $definition = MachineDefinition::define(config: [
        'id'      => 'rok_fqcn',
        'initial' => 'idle',
        'states'  => ['idle' => []],
    ]);

    $resolved = $definition->resolveOutputKey(DoubleAmountOutput::class);

    expect($resolved)->toBeInstanceOf(DoubleAmountOutput::class);
});

it('resolveOutputKey resolves inline key to class from registry', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'rok_inline_class',
            'initial' => 'idle',
            'states'  => ['idle' => []],
        ],
        behavior: [
            'outputs' => [
                'myOutput' => DoubleAmountOutput::class,
            ],
        ],
    );

    $resolved = $definition->resolveOutputKey('myOutput');

    expect($resolved)->toBeInstanceOf(DoubleAmountOutput::class);
});

it('resolveOutputKey resolves inline key to closure from registry', function (): void {
    $closure = fn (ContextManager $ctx): array => ['total' => $ctx->get('amount')];

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'rok_inline_closure',
            'initial' => 'idle',
            'states'  => ['idle' => []],
        ],
        behavior: [
            'outputs' => [
                'sumOutput' => $closure,
            ],
        ],
    );

    $resolved = $definition->resolveOutputKey('sumOutput');

    expect($resolved)->toBeCallable();
});

it('resolveOutputKey throws BehaviorNotFoundException for unknown key', function (): void {
    $definition = MachineDefinition::define(config: [
        'id'      => 'rok_unknown',
        'initial' => 'idle',
        'states'  => ['idle' => []],
    ]);

    $definition->resolveOutputKey('nonExistentOutput');
})->throws(BehaviorNotFoundException::class);

it('resolveOutputKey prefers FQCN over registry when key is a valid class name', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'rok_precedence',
            'initial' => 'idle',
            'states'  => ['idle' => []],
        ],
        behavior: [
            'outputs' => [
                // Registry entry with same key as FQCN — FQCN should win
                DoubleAmountOutput::class => fn (): array => ['registry' => true],
            ],
        ],
    );

    $resolved = $definition->resolveOutputKey(DoubleAmountOutput::class);

    // FQCN resolves via container → DoubleAmountOutput instance, NOT the registry closure
    expect($resolved)->toBeInstanceOf(DoubleAmountOutput::class);
});

// ═══════════════════════════════════════════════════════════════
//  Machine::output() — Inline Key Integration
// ═══════════════════════════════════════════════════════════════

it('Machine::output() resolves inline key on non-final state', function (): void {
    $test = TestMachine::define(
        config: [
            'initial' => 'active',
            'context' => ['amount' => 50],
            'states'  => [
                'active' => [
                    'output' => 'customOutput',
                    'on'     => ['DONE' => 'completed'],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'outputs' => [
                'customOutput' => DoubleAmountOutput::class,
            ],
        ],
    );

    expect($test->machine()->output())->toBe(['doubled' => 100]);
});

it('Machine::output() resolves inline key on final state', function (): void {
    $test = TestMachine::define(
        config: [
            'initial' => 'idle',
            'context' => ['amount' => 25],
            'states'  => [
                'idle' => ['on' => ['FINISH' => 'done']],
                'done' => [
                    'type'   => 'final',
                    'output' => 'customOutput',
                ],
            ],
        ],
        behavior: [
            'outputs' => [
                'customOutput' => DoubleAmountOutput::class,
            ],
        ],
    );

    $machine = $test->machine();
    $machine->send(['type' => 'FINISH']);

    expect($machine->output())->toBe(['doubled' => 50]);
});

it('Machine::output() resolves inline closure from registry', function (): void {
    $test = TestMachine::define(
        config: [
            'initial' => 'active',
            'context' => ['total' => 99],
            'states'  => [
                'active' => [
                    'output' => 'sumOutput',
                    'on'     => ['DONE' => 'completed'],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'outputs' => [
                'sumOutput' => fn (ContextManager $ctx): array => ['sum' => $ctx->get('total')],
            ],
        ],
    );

    expect($test->machine()->output())->toBe(['sum' => 99]);
});

it('Machine::output() resolves FQCN class on state output', function (): void {
    $test = TestMachine::define(
        config: [
            'initial' => 'active',
            'context' => ['amount' => 10],
            'states'  => [
                'active' => [
                    'output' => DoubleAmountOutput::class,
                    'on'     => ['DONE' => 'completed'],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
    );

    expect($test->machine()->output())->toBe(['doubled' => 20]);
});

it('Machine::output() throws for unknown inline key', function (): void {
    $test = TestMachine::define(
        config: [
            'initial' => 'active',
            'context' => ['amount' => 10],
            'states'  => [
                'active' => [
                    'output' => 'unknownOutput',
                    'on'     => ['DONE' => 'completed'],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
    );

    $test->machine()->output();
})->throws(BehaviorNotFoundException::class);

it('Machine::output() resolves compound state output when child has none', function (): void {
    $test = TestMachine::define(
        config: [
            'initial' => 'parent',
            'context' => ['amount' => 30],
            'states'  => [
                'parent' => [
                    'initial' => 'child_a',
                    'output'  => 'customOutput',
                    'states'  => [
                        'child_a' => [],
                    ],
                ],
            ],
        ],
        behavior: [
            'outputs' => [
                'customOutput' => DoubleAmountOutput::class,
            ],
        ],
    );

    expect($test->machine()->output())->toBe(['doubled' => 60]);
});

// ═══════════════════════════════════════════════════════════════
//  resolveChildOutput() — Integration Tests
// ═══════════════════════════════════════════════════════════════

it('resolveChildOutput resolves inline key from child behavior registry', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'child_inline',
            'initial' => 'processing',
            'context' => ['amount' => 42],
            'states'  => [
                'processing' => ['on' => ['COMPLETE' => 'done']],
                'done'       => [
                    'type'   => 'final',
                    'output' => 'childOutput',
                ],
            ],
        ],
        behavior: [
            'outputs' => [
                'childOutput' => DoubleAmountOutput::class,
            ],
        ],
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'COMPLETE'], $state);

    $result = MachineDefinition::resolveChildOutput($state->currentStateDefinition, $state->context);

    expect($result)->toBe(['doubled' => 84]);
});

it('resolveChildOutput resolves FQCN class', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'child_fqcn',
            'initial' => 'processing',
            'context' => ['amount' => 10],
            'states'  => [
                'processing' => ['on' => ['COMPLETE' => 'done']],
                'done'       => [
                    'type'   => 'final',
                    'output' => DoubleAmountOutput::class,
                ],
            ],
        ],
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'COMPLETE'], $state);

    $result = MachineDefinition::resolveChildOutput($state->currentStateDefinition, $state->context);

    expect($result)->toBe(['doubled' => 20]);
});

it('resolveChildOutput resolves closure from registry via inline key', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'child_inline_closure',
            'initial' => 'processing',
            'context' => ['amount' => 77],
            'states'  => [
                'processing' => ['on' => ['COMPLETE' => 'done']],
                'done'       => [
                    'type'   => 'final',
                    'output' => 'closureOutput',
                ],
            ],
        ],
        behavior: [
            'outputs' => [
                'closureOutput' => fn (ContextManager $ctx): array => ['tripled' => $ctx->get('amount') * 3],
            ],
        ],
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'COMPLETE'], $state);

    $result = MachineDefinition::resolveChildOutput($state->currentStateDefinition, $state->context);

    expect($result)->toBe(['tripled' => 231]);
});

it('resolveChildOutput returns null when output is null', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'child_null',
            'initial' => 'processing',
            'context' => ['amount' => 10],
            'states'  => [
                'processing' => ['on' => ['COMPLETE' => 'done']],
                'done'       => ['type' => 'final'],
            ],
        ],
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'COMPLETE'], $state);

    $result = MachineDefinition::resolveChildOutput($state->currentStateDefinition, $state->context);

    expect($result)->toBeNull();
});

it('resolveChildOutput filters context when output is array', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'child_array',
            'initial' => 'processing',
            'context' => ['amount' => 10, 'status' => 'ok', 'internal' => 'hidden'],
            'states'  => [
                'processing' => ['on' => ['COMPLETE' => 'done']],
                'done'       => [
                    'type'   => 'final',
                    'output' => ['amount', 'status'],
                ],
            ],
        ],
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'COMPLETE'], $state);

    $result = MachineDefinition::resolveChildOutput($state->currentStateDefinition, $state->context);

    expect($result)->toBe(['amount' => 10, 'status' => 'ok']);
});

it('resolveChildOutput calls closure from state config directly', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'child_closure_direct',
            'initial' => 'processing',
            'context' => ['amount' => 5],
            'states'  => [
                'processing' => ['on' => ['COMPLETE' => 'done']],
                'done'       => [
                    'type'   => 'final',
                    'output' => fn (ContextManager $ctx): array => ['squared' => $ctx->get('amount') ** 2],
                ],
            ],
        ],
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'COMPLETE'], $state);

    $result = MachineDefinition::resolveChildOutput($state->currentStateDefinition, $state->context);

    expect($result)->toBe(['squared' => 25]);
});

it('resolveChildOutput throws for unknown inline key', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'child_unknown',
            'initial' => 'processing',
            'context' => ['amount' => 10],
            'states'  => [
                'processing' => ['on' => ['COMPLETE' => 'done']],
                'done'       => [
                    'type'   => 'final',
                    'output' => 'missingOutput',
                ],
            ],
        ],
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'COMPLETE'], $state);

    MachineDefinition::resolveChildOutput($state->currentStateDefinition, $state->context);
})->throws(BehaviorNotFoundException::class);

// ═══════════════════════════════════════════════════════════════
//  Cross-cutting: Same inline key reused across states
// ═══════════════════════════════════════════════════════════════

it('same inline key works across multiple states', function (): void {
    $test = TestMachine::define(
        config: [
            'initial' => 'step_one',
            'context' => ['amount' => 10],
            'states'  => [
                'step_one' => [
                    'output' => 'customOutput',
                    'on'     => ['NEXT' => 'step_two'],
                ],
                'step_two' => [
                    'output' => 'customOutput',
                    'on'     => ['DONE' => 'completed'],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'outputs' => [
                'customOutput' => DoubleAmountOutput::class,
            ],
        ],
    );

    $machine = $test->machine();
    expect($machine->output())->toBe(['doubled' => 20]);

    $machine->send(['type' => 'NEXT']);
    expect($machine->output())->toBe(['doubled' => 20]);
});
