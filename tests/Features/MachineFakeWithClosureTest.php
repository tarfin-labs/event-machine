<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Guards\IsAllowedGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateChildMachine;

afterEach(function (): void {
    InvokableBehavior::resetAllFakes();
    Machine::resetMachineFakes();
});

// ============================================================
// Machine::fake() with crashing with closure
// ============================================================

it('Machine::fake() works when with closure accesses null model properties', function (): void {
    Queue::fake();

    ImmediateChildMachine::fake(result: ['status' => 'ok']);

    // Define a parent with a with closure that accesses a null property
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'closure_crash_test',
            'initial' => 'idle',
            'context' => ['user' => null],  // user is null — closure will crash
            'states'  => [
                'idle' => [
                    'on' => ['START' => 'delegating'],
                ],
                'delegating' => [
                    'machine' => ImmediateChildMachine::class,
                    'with'    => fn (ContextManager $ctx): array => [
                        'user_name' => $ctx->get('user')->name,  // crashes: null->name
                    ],
                    '@done' => 'completed',
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(event: ['type' => 'START'], state: $state);

    // Faked machine should complete — with closure crash is caught
    expect($state->value)->toBe(['closure_crash_test.completed']);

    // Child was invoked (with empty context due to closure crash)
    ImmediateChildMachine::assertInvoked();
});

it('Machine::fake() gracefully handles crashing with closure via try-catch', function (): void {
    Queue::fake();

    ImmediateChildMachine::fake(result: ['status' => 'ok']);

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'closure_graceful_test',
            'initial' => 'delegating',
            'context' => ['user' => null],
            'states'  => [
                'delegating' => [
                    'machine' => ImmediateChildMachine::class,
                    'with'    => fn (ContextManager $ctx): array => [
                        'user_name' => $ctx->get('user')->name,  // will crash
                    ],
                    '@done' => 'completed',
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    $state = $definition->getInitialState();

    // Machine should reach completed — try-catch prevents crash
    expect($state->value)->toBe(['closure_graceful_test.completed']);
});

// ============================================================
// InteractsWithMachines resets class-based spies
// ============================================================

it('InteractsWithMachines resets class-based spies via resetAllFakes', function (): void {
    // Spy a guard
    IsAllowedGuard::spy();
    expect(IsAllowedGuard::isFaked())->toBeTrue();

    // Reset (simulates what InteractsWithMachines::tearDown does)
    InvokableBehavior::resetAllFakes();

    // Guard should no longer be faked
    expect(IsAllowedGuard::isFaked())->toBeFalse();
});
