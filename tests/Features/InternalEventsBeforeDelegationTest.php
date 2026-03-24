<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\InternalBeforeDelegation\RaiseSetupAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\InternalBeforeDelegation\RaiseRedirectAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\InternalBeforeDelegation\LogRedirectedEntryAction;

// ============================================================
// Internal Events Complete Before Child Machine Delegation
// (Apache Commons SCXML invoker-05 macrostep semantics)
// ============================================================

test('internal events raised in entry are processed before sync child machine invocation', function (): void {
    // Scenario:
    // - State 'delegating' has entry action that raises REDIRECT
    // - State 'delegating' has sync child machine (ImmediateChildMachine)
    // - State 'delegating' has 'on: REDIRECT' -> 'redirected'
    // - State 'delegating' has '@done' -> 'child_completed'
    //
    // Per SCXML macrostep semantics (invoker-05), internal events must
    // be fully processed BEFORE any child machine invocation starts.
    //
    // Expected: REDIRECT fires first -> machine transitions to 'redirected'
    //           Child machine is never invoked because we left 'delegating'.

    $machine = MachineDefinition::define(
        config: [
            'id'      => 'internal_before_delegation',
            'initial' => 'idle',
            'context' => [
                'execution_order' => [],
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'START' => 'delegating',
                    ],
                ],
                'delegating' => [
                    'entry'   => RaiseRedirectAction::class,
                    'machine' => ImmediateChildMachine::class,
                    '@done'   => 'child_completed',
                    'on'      => [
                        'REDIRECT' => [
                            'target'  => 'redirected',
                            'actions' => 'logRedirectAction',
                        ],
                    ],
                ],
                'redirected' => [
                    'entry' => LogRedirectedEntryAction::class,
                    'type'  => 'final',
                ],
                'child_completed' => [
                    'type' => 'final',
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'logRedirectAction' => function (ContextManager $context): void {
                    $order   = $context->get('execution_order');
                    $order[] = 'transition:redirect';
                    $context->set('execution_order', $order);
                },
            ],
        ],
    );

    $state = $machine->transition(event: ['type' => 'START']);

    // Internal event (REDIRECT) should be processed before child delegation.
    // Machine should be in 'redirected', NOT 'child_completed'.
    expect($state->matches('redirected'))->toBeTrue();

    expect($state->context->get('execution_order'))->toBe([
        'entry:raise_redirect',  // 1. Entry action runs and raises REDIRECT
        'transition:redirect',   // 2. REDIRECT processed -> transition to redirected
        'entry:redirected',      // 3. Redirected state entered
    ]);
});

test('sync delegation still works when no internal events are raised in entry', function (): void {
    // Sanity check: when entry actions do NOT raise events,
    // child delegation should proceed normally.

    $machine = MachineDefinition::define(
        config: [
            'id'      => 'delegation_no_raise',
            'initial' => 'idle',
            'context' => [
                'execution_order' => [],
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'START' => 'delegating',
                    ],
                ],
                'delegating' => [
                    'entry'   => 'logEntryAction',
                    'machine' => ImmediateChildMachine::class,
                    '@done'   => 'child_completed',
                ],
                'child_completed' => [
                    'entry' => 'logChildCompletedAction',
                    'type'  => 'final',
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'logEntryAction' => function (ContextManager $context): void {
                    $order   = $context->get('execution_order');
                    $order[] = 'entry:delegating';
                    $context->set('execution_order', $order);
                },
                'logChildCompletedAction' => function (ContextManager $context): void {
                    $order   = $context->get('execution_order');
                    $order[] = 'entry:child_completed';
                    $context->set('execution_order', $order);
                },
            ],
        ],
    );

    $state = $machine->transition(event: ['type' => 'START']);

    // No raised events -> child delegation proceeds normally
    expect($state->matches('child_completed'))->toBeTrue();

    expect($state->context->get('execution_order'))->toBe([
        'entry:delegating',       // 1. Entry action (no raise)
        'entry:child_completed',  // 2. Child completes, @done -> child_completed
    ]);
});

test('raised event transitions machine away so child delegation is skipped entirely', function (): void {
    // Same pattern as test 1 but with a different raised event (SETUP).
    // Verifies the behavior is consistent: any internal event that causes
    // a transition will pre-empt child machine invocation.

    $machine = MachineDefinition::define(
        config: [
            'id'      => 'raise_skips_delegation',
            'initial' => 'idle',
            'context' => [
                'execution_order' => [],
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'START' => 'delegating',
                    ],
                ],
                'delegating' => [
                    'entry'   => RaiseSetupAction::class,
                    'machine' => ImmediateChildMachine::class,
                    '@done'   => 'child_completed',
                    'on'      => [
                        'SETUP' => [
                            'target'  => 'setting_up',
                            'actions' => 'logSetupTransitionAction',
                        ],
                    ],
                ],
                'setting_up' => [
                    'entry' => 'logSettingUpEntryAction',
                    'type'  => 'final',
                ],
                'child_completed' => [
                    'type' => 'final',
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'logSetupTransitionAction' => function (ContextManager $context): void {
                    $order   = $context->get('execution_order');
                    $order[] = 'transition:setup';
                    $context->set('execution_order', $order);
                },
                'logSettingUpEntryAction' => function (ContextManager $context): void {
                    $order   = $context->get('execution_order');
                    $order[] = 'entry:setting_up';
                    $context->set('execution_order', $order);
                },
            ],
        ],
    );

    $state = $machine->transition(event: ['type' => 'START']);

    // SETUP event should be processed first -> machine moves to setting_up.
    // Child delegation on 'delegating' is skipped entirely.
    expect($state->matches('setting_up'))->toBeTrue();

    expect($state->context->get('execution_order'))->toBe([
        'entry:raise_setup',     // 1. Entry action raises SETUP
        'transition:setup',      // 2. SETUP processed -> transition to setting_up
        'entry:setting_up',      // 3. setting_up state entered
    ]);
});
