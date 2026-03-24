<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Behavior\ValidationGuardBehavior;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\MachineValidationException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\FailingChildMachine;

// region Test 1: parallel-entry-error-clean-exit
// Error in parallel region triggers @fail and exits cleanly to error state.

it('routes to @fail and exits cleanly when parallel region fails', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'parallel_entry_error',
            'initial' => 'processing',
            'context' => [
                'error_caught' => false,
            ],
            'states' => [
                'processing' => [
                    'type'   => 'parallel',
                    '@done'  => 'completed',
                    '@fail'  => [
                        'target'  => 'error_state',
                        'actions' => 'markErrorCaughtAction',
                    ],
                    'states' => [
                        'region_a' => [
                            'initial' => 'working',
                            'states'  => [
                                'working'  => ['on' => ['DONE_A' => 'finished']],
                                'finished' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'working',
                            'states'  => [
                                'working'  => ['on' => ['DONE_B' => 'finished']],
                                'finished' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'completed'   => ['type' => 'final'],
                'error_state' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'markErrorCaughtAction' => function (ContextManager $ctx): void {
                    $ctx->set('error_caught', true);
                },
            ],
        ],
    );

    $state = $definition->getInitialState();

    // Verify machine is in parallel state with both regions active
    expect($state->value)->toContain('parallel_entry_error.processing.region_a.working')
        ->and($state->value)->toContain('parallel_entry_error.processing.region_b.working');

    // Simulate a region failure (e.g., entry action threw in one region)
    $parallelState = $definition->idMap['parallel_entry_error.processing'];
    $state         = $definition->processParallelOnFail($parallelState, $state);

    // @fail fires, machine exits parallel state cleanly and transitions to error_state
    expect($state->value)->toBe(['parallel_entry_error.error_state'])
        ->and($state->context->get('error_caught'))->toBeTrue()
        ->and($state->isInParallelState())->toBeFalse();
});

// endregion

// region Test 2: validation-guard-bypasses-fail
// ValidationGuardBehavior exception always propagates (not routed through @fail).

it('throws MachineValidationException instead of routing through @fail when ValidationGuard rejects', function (): void {
    $failReached = false;

    $machine = Machine::create([
        'config' => [
            'id'      => 'validation_bypasses_fail',
            'initial' => 'idle',
            'context' => [
                'fail_reached' => false,
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'SUBMIT' => [
                            'target' => 'submitted',
                            'guards' => 'alwaysRejectValidationGuard',
                        ],
                    ],
                    'machine' => FailingChildMachine::class,
                    '@fail'   => [
                        'target'  => 'failed',
                        'actions' => 'markFailReachedAction',
                    ],
                ],
                'submitted' => ['type' => 'final'],
                'failed'    => ['type' => 'final'],
            ],
        ],
        'behavior' => [
            'guards' => [
                'alwaysRejectValidationGuard' => new class extends ValidationGuardBehavior
                {
                    public function __invoke(): bool
                    {
                        $this->errorMessage = 'Always rejects';

                        return false;
                    }
                },
            ],
            'actions' => [
                'markFailReachedAction' => function () use (&$failReached): void {
                    $failReached = true;
                },
            ],
        ],
    ]);

    expect(fn () => $machine->send(['type' => 'SUBMIT']))
        ->toThrow(MachineValidationException::class);

    // Machine must stay in 'idle' — @fail NOT triggered
    expect($machine->state->currentStateDefinition->key)->toBe('idle')
        ->and($failReached)->toBeFalse();
});

// endregion

// region Test 3: conditional-fail-routing
// Conditional @fail routing based on error context — guards check the child fail event payload.

it('routes to correct @fail branch based on guard conditions', function (): void {
    // Test the conditional @fail routing with guards checking context.
    // retry_count=0 → CanRetry guard passes → retrying branch
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'conditional_fail_routing',
            'initial' => 'processing',
            'context' => [
                'retry_count'   => 0,
                'alert_sent'    => false,
                'retried'       => false,
                'fallback_hit'  => false,
            ],
            'states' => [
                'processing' => [
                    'type'  => 'parallel',
                    '@done' => 'completed',
                    '@fail' => [
                        ['target' => 'retrying', 'guards' => 'canRetryGuard', 'actions' => 'incrementRetryAndMarkAction'],
                        ['target' => 'critical_failure', 'guards' => 'tooManyRetriesGuard', 'actions' => 'sendAlertAction'],
                        ['target' => 'generic_error'],
                    ],
                    'states' => [
                        'region_a' => [
                            'initial' => 'working',
                            'states'  => [
                                'working'  => ['on' => ['DONE_A' => 'finished']],
                                'finished' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'working',
                            'states'  => [
                                'working'  => ['on' => ['DONE_B' => 'finished']],
                                'finished' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'completed'        => ['type' => 'final'],
                'retrying'         => ['type' => 'final'],
                'critical_failure' => ['type' => 'final'],
                'generic_error'    => ['type' => 'final'],
            ],
        ],
        behavior: [
            'guards' => [
                'canRetryGuard' => function (ContextManager $ctx): bool {
                    return $ctx->get('retry_count') < 3;
                },
                'tooManyRetriesGuard' => function (ContextManager $ctx): bool {
                    return $ctx->get('retry_count') >= 10;
                },
            ],
            'actions' => [
                'incrementRetryAndMarkAction' => function (ContextManager $ctx): void {
                    $ctx->set('retry_count', $ctx->get('retry_count') + 1);
                    $ctx->set('retried', true);
                },
                'sendAlertAction' => function (ContextManager $ctx): void {
                    $ctx->set('alert_sent', true);
                },
            ],
        ],
    );

    // Scenario 1: retry_count=0 → canRetryGuard passes → retrying
    $state         = $definition->getInitialState();
    $parallelState = $definition->idMap['conditional_fail_routing.processing'];
    $state         = $definition->processParallelOnFail($parallelState, $state);

    expect($state->value)->toBe(['conditional_fail_routing.retrying'])
        ->and($state->context->get('retried'))->toBeTrue()
        ->and($state->context->get('retry_count'))->toBe(1)
        ->and($state->context->get('alert_sent'))->toBeFalse();

    // Scenario 2: retry_count=10 → canRetryGuard fails, tooManyRetriesGuard passes → critical_failure
    $state2 = $definition->getInitialState();
    $state2->context->set('retry_count', 10);
    $state2 = $definition->processParallelOnFail($parallelState, $state2);

    expect($state2->value)->toBe(['conditional_fail_routing.critical_failure'])
        ->and($state2->context->get('alert_sent'))->toBeTrue();

    // Scenario 3: retry_count=5 → canRetryGuard fails (5 >= 3), tooManyRetriesGuard fails (5 < 10) → generic_error (no-guard fallback)
    $state3 = $definition->getInitialState();
    $state3->context->set('retry_count', 5);
    $state3 = $definition->processParallelOnFail($parallelState, $state3);

    expect($state3->value)->toBe(['conditional_fail_routing.generic_error'])
        ->and($state3->context->get('alert_sent'))->toBeFalse()
        ->and($state3->context->get('retried'))->toBeFalse();
});

// endregion

// region Test 4: recovery-after-fail
// Machine continues processing events after @fail transition to non-final error state.

it('can transition out of error state after @fail routes there', function (): void {
    $machine = Machine::create([
        'config' => [
            'id'      => 'recovery_after_fail',
            'initial' => 'idle',
            'context' => [
                'error_message' => null,
                'recovered'     => false,
            ],
            'states' => [
                'idle' => [
                    'on' => ['START' => 'processing'],
                ],
                'processing' => [
                    'machine' => FailingChildMachine::class,
                    '@done'   => 'completed',
                    '@fail'   => [
                        'target'  => 'error_state',
                        'actions' => 'captureErrorAction',
                    ],
                ],
                'error_state' => [
                    'on' => [
                        'RETRY' => [
                            'target'  => 'recovered',
                            'actions' => 'markRecoveredAction',
                        ],
                        'ABORT' => 'aborted',
                    ],
                ],
                'completed' => ['type' => 'final'],
                'recovered' => ['type' => 'final'],
                'aborted'   => ['type' => 'final'],
            ],
        ],
        'behavior' => [
            'actions' => [
                'captureErrorAction' => function (ContextManager $ctx): void {
                    $ctx->set('error_message', 'Child failed');
                },
                'markRecoveredAction' => function (ContextManager $ctx): void {
                    $ctx->set('recovered', true);
                },
            ],
        ],
    ]);

    // Step 1: Transition to processing — child fails, @fail routes to error_state
    $state = $machine->send(['type' => 'START']);

    expect($state->currentStateDefinition->key)->toBe('error_state')
        ->and($state->context->get('error_message'))->toBe('Child failed');

    // Step 2: Send RETRY — machine recovers from error state
    $state = $machine->send(['type' => 'RETRY']);

    expect($state->currentStateDefinition->key)->toBe('recovered')
        ->and($state->context->get('recovered'))->toBeTrue();
});

// endregion

// region Test 5: error-in-error-handler
// Error in @fail's target entry action does not cause infinite @fail→@fail loop.

it('propagates exception when @fail target entry action throws instead of looping', function (): void {
    $machine = Machine::create([
        'config' => [
            'id'      => 'error_in_error_handler',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['START' => 'processing'],
                ],
                'processing' => [
                    'machine' => FailingChildMachine::class,
                    '@done'   => 'completed',
                    '@fail'   => 'broken_error_handler',
                ],
                'broken_error_handler' => [
                    'entry' => 'throwInErrorHandlerAction',
                    '@fail' => 'safe_fallback',
                ],
                'completed'     => ['type' => 'final'],
                'safe_fallback' => ['type' => 'final'],
            ],
        ],
        'behavior' => [
            'actions' => [
                'throwInErrorHandlerAction' => function (): void {
                    throw new RuntimeException('Error handler itself broke');
                },
            ],
        ],
    ]);

    // The child machine fails (FailingChildMachine throws on entry),
    // @fail routes to broken_error_handler, whose entry also throws.
    // This must NOT cause a @fail→@fail infinite loop — exception should propagate.
    expect(fn () => $machine->send(['type' => 'START']))
        ->toThrow(RuntimeException::class, 'Error handler itself broke');
});

// endregion
