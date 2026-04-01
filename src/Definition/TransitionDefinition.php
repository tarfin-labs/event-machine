<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

use Throwable;
use Mockery\MockInterface;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Support\Timer;
use Tarfinlabs\EventMachine\Enums\BehaviorType;
use Tarfinlabs\EventMachine\Enums\InternalEvent;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;
use Tarfinlabs\EventMachine\Enums\TransitionProperty;
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;
use Tarfinlabs\EventMachine\Testing\InlineBehaviorFake;
use Tarfinlabs\EventMachine\Support\BehaviorTupleParser;
use Tarfinlabs\EventMachine\Behavior\ValidationGuardBehavior;

/**
 * Class TransitionDefinition.
 *
 * Represents a transition between states in the state machine.
 */
class TransitionDefinition
{
    // region Public Properties

    /** The transition branches for this transition, or null if there is no target. */
    public ?array $branches = null;

    /** The description of the transition. */
    public ?string $description = null;

    /** Indicates whether the transition is guarded or not. */
    public bool $isGuarded = false;

    /** This variable determines whether the condition is always false. */
    public bool $isAlways = false;

    /** Timer definition for this transition (after/every key), or null if no timer. */
    public ?TimerDefinition $timerDefinition = null;

    // endregion

    // region Constructor

    /**
     * Constructs a new TransitionDefinition instance.
     *
     * @param  null|string|array  $transitionConfig  The configuration for this transition.
     * @param  StateDefinition  $source  The source state definition for this transition.
     * @param  string  $event  The event triggering this transition.
     */
    public function __construct(
        public null|string|array $transitionConfig,
        public StateDefinition $source,
        public string $event,
    ) {
        if ($this->event === TransitionProperty::Always->value) {
            $this->isAlways = true;
        }

        // Normalize empty values to null (targetless transition)
        if ($this->transitionConfig === '' || $this->transitionConfig === []) {
            $this->transitionConfig = null;
        }

        $this->description = $this->transitionConfig['description'] ?? null;

        // Extract timer config (after/every/max/then) before processing branches
        $this->extractTimerConfig();

        if ($this->transitionConfig === null) {
            $this->branches[] = new TransitionBranch(
                transitionBranchConfig: $this->transitionConfig,
                transitionDefinition: $this
            );

            return;
        }

        if (is_string($this->transitionConfig)) {
            $this->branches[] = new TransitionBranch(
                transitionBranchConfig: $this->transitionConfig,
                transitionDefinition: $this
            );

            return;
        }

        if ($this->isAMultiPathGuardedTransition($this->transitionConfig) === false) {
            $this->transitionConfig = [$this->transitionConfig];
        }

        // If the transition has multiple branches, it is a guarded transition
        if (count($this->transitionConfig) > 1) {
            $this->isGuarded = true;
        }

        foreach ($this->transitionConfig as $config) {
            $this->branches[] = new TransitionBranch($config, $this);
        }
    }

    // endregion

    // region Protected Methods

    /**
     * Extract timer configuration (after/every/max/then) from transition config.
     *
     * Handles both single-branch configs (associative array with 'after'/'every')
     * and multi-branch configs (mixed array with numeric branch entries + string timer keys).
     * Removes timer keys from transitionConfig so branch parsing is unaffected.
     */
    protected function extractTimerConfig(): void
    {
        if (!is_array($this->transitionConfig)) {
            return;
        }

        $after = $this->transitionConfig['after'] ?? null;
        $every = $this->transitionConfig['every'] ?? null;

        if ($after === null && $every === null) {
            return;
        }

        $stateId = $this->source->id;
        $max     = $this->transitionConfig['max'] ?? null;
        $then    = $this->transitionConfig['then'] ?? null;

        if ($after instanceof Timer) {
            $this->timerDefinition = TimerDefinition::fromAfter($after, $this->event, $stateId);
        } elseif ($every instanceof Timer) {
            $this->timerDefinition = TimerDefinition::fromEvery($every, $this->event, $stateId, $max, $then);
        }

        // Remove timer keys from config so branch parsing is clean
        unset(
            $this->transitionConfig['after'],
            $this->transitionConfig['every'],
            $this->transitionConfig['max'],
            $this->transitionConfig['then'],
        );

        // If config is now empty (timer was the only content), set to null
        if ($this->transitionConfig === []) {
            $this->transitionConfig = null;
        }
    }

    /**
     * Determines if the given transition configuration represents a multi-path guarded transition.
     * This method checks if the provided array has numeric keys and array values, indicating
     * that it contains multiple guarded transitions based on different guards.
     *
     * @param  array|string|null  $transitionConfig  The transition configuration to examine.
     *
     * @return bool True if the configuration represents a multi-path guarded transition, false otherwise.
     */
    protected function isAMultiPathGuardedTransition(null|array|string $transitionConfig): bool
    {
        if (is_null($transitionConfig) || is_string($transitionConfig) || $transitionConfig === []) {
            return false;
        }

        // Iterate through the input array
        foreach ($transitionConfig as $key => $value) {
            // Check if the key is numeric and the value is an array
            if (!is_int($key) || !is_array($value)) {
                return false;
            }
        }

        return true;
    }

    // endregion

    // region Public Methods

    /**
     * Selects the first eligible transition while evaluating guards.
     *
     * This method iterates through the given transition candidates and
     * checks if all the guards are passed. If a candidate transition
     * does not have any guards, it is considered eligible.
     * If a transition with guards has all its guards evaluated
     * to true, it is considered eligible. The method returns the first
     * eligible transition encountered or null if none is found.
     *
     * @param  EventBehavior  $eventBehavior  The event used to evaluate guards.
     *
     * @return TransitionDefinition|null The first eligible transition or
     *                                   null if no eligible transition is found.
     *
     * @throws \ReflectionException
     */
    public function getFirstValidTransitionBranch(
        EventBehavior $eventBehavior,
        State $state
    ): ?TransitionBranch {
        /* @var TransitionBranch $branch */
        foreach ($this->branches as $branch) {
            if ($this->runCalculators($state, $eventBehavior, $branch) === false) {
                return null;
            }

            if (!isset($branch->guards)) {
                return $branch;
            }

            // Snapshot context data before guard evaluation so that
            // side-effects from a failing guard do not leak.
            $contextDataBeforeGuards = $state->context->data;
            $guardsPassed            = true;
            foreach ($branch->guards as $guardDefinition) {
                $configParams   = null;
                $guardArguments = null;

                if (is_array($guardDefinition)) {
                    // Named params tuple: [GuardClass::class, 'min' => 100, 'max' => 10000]
                    $parsed          = BehaviorTupleParser::parse($guardDefinition, 'guards');
                    $guardDefinition = $parsed['definition'];
                    $configParams    = $parsed['configParams'] !== [] ? $parsed['configParams'] : null;
                } elseif (is_string($guardDefinition) && str_contains($guardDefinition, ':')) {
                    // Deprecated colon syntax: 'guardName:arg1,arg2'
                    @trigger_error('The colon syntax "behavior:arg1,arg2" is deprecated since tarfin-labs/event-machine 9.0. Use named params tuple [[Class::class, \'param\' => value]] instead.', E_USER_DEPRECATED);
                    [$guardDefinition, $colonArgs] = explode(':', $guardDefinition, 2);
                    $guardArguments                = explode(',', $colonArgs);
                }

                $guardBehavior = $this->source->machine->getInvokableBehavior(
                    behaviorDefinition: $guardDefinition,
                    behaviorType: BehaviorType::Guard
                );

                $shouldLog = $guardBehavior->shouldLog ?? false;

                if ($guardBehavior instanceof GuardBehavior && !$guardBehavior instanceof MockInterface) {
                    $guardBehavior::validateRequiredContext($state->context);
                }

                // Inject guard behavior parameters
                $guardBehaviorParameters = InvokableBehavior::injectInvokableBehaviorParameters(
                    actionBehavior: $guardBehavior,
                    state: $state,
                    eventBehavior: $eventBehavior,
                    actionArguments: $guardArguments,
                    configParams: $configParams,
                );

                // Execute the guard behavior
                if (InlineBehaviorFake::intercept($guardDefinition, $guardBehaviorParameters)) {
                    $replacement = InlineBehaviorFake::getReplacement($guardDefinition);
                    $guardResult = ($replacement)(...$guardBehaviorParameters);
                } else {
                    $guardResult = ($guardBehavior)(...$guardBehaviorParameters);
                }

                if ($guardResult === false) {
                    $guardsPassed = false;

                    $payload = null;
                    if ($guardBehavior instanceof ValidationGuardBehavior) {
                        $errorMessage = $guardBehavior->errorMessage;
                        $errorKey     = InternalEvent::GUARD_FAIL->generateInternalEventName(
                            machineId: $this->source->machine->id,
                            placeholder: $guardBehavior::getType()
                        );

                        $payload = [$errorKey => $errorMessage];
                    }

                    // Record the internal guard fail event.
                    $state->setInternalEventBehavior(
                        type: InternalEvent::GUARD_FAIL,
                        placeholder: $guardDefinition,
                        payload: $payload,
                        shouldLog: $shouldLog,
                    );

                    break;
                }

                // Record the internal guard pass event.
                $state->setInternalEventBehavior(
                    type: InternalEvent::GUARD_PASS,
                    placeholder: $guardDefinition,
                    shouldLog: $shouldLog,
                );
            }

            if ($guardsPassed) {
                return $branch;
            }

            // Guards failed — restore context to pre-guard snapshot
            // so that guard side-effects do not leak into later branches.
            $state->context->data = $contextDataBeforeGuards;
        }

        return null;
    }

    /**
     * Executes calculator behaviors associated with this transition.
     *
     * Returns false if any calculator fails, preventing the transition.
     */
    public function runCalculators(
        State $state,
        EventBehavior $eventBehavior,
        TransitionBranch $branch,
    ): bool {
        if ($branch->calculators === null) {
            return true;
        }

        foreach ($branch->calculators as $calculatorDefinition) {
            $configParams        = null;
            $calculatorArguments = null;

            if (is_array($calculatorDefinition)) {
                $parsed               = BehaviorTupleParser::parse($calculatorDefinition, 'calculators');
                $calculatorDefinition = $parsed['definition'];
                $configParams         = $parsed['configParams'] !== [] ? $parsed['configParams'] : null;
            } elseif (is_string($calculatorDefinition) && str_contains($calculatorDefinition, ':')) {
                @trigger_error('The colon syntax "behavior:arg1,arg2" is deprecated since tarfin-labs/event-machine 9.0. Use named params tuple [[Class::class, \'param\' => value]] instead.', E_USER_DEPRECATED);
                [$calculatorDefinition, $colonArgs] = explode(':', $calculatorDefinition, 2);
                $calculatorArguments                = explode(',', $colonArgs);
            }

            $calculatorBehavior = $this->source->machine->getInvokableBehavior(
                behaviorDefinition: $calculatorDefinition,
                behaviorType: BehaviorType::Calculator
            );

            $shouldLog = $calculatorBehavior->shouldLog ?? false;

            try {
                $calculatorParameters = InvokableBehavior::injectInvokableBehaviorParameters(
                    actionBehavior: $calculatorBehavior,
                    state: $state,
                    eventBehavior: $eventBehavior,
                    actionArguments: $calculatorArguments,
                    configParams: $configParams,
                );

                if (InlineBehaviorFake::intercept($calculatorDefinition, $calculatorParameters)) {
                    $replacement = InlineBehaviorFake::getReplacement($calculatorDefinition);
                    ($replacement)(...$calculatorParameters);
                } else {
                    ($calculatorBehavior)(...$calculatorParameters);
                }

                $state->setInternalEventBehavior(
                    type: InternalEvent::CALCULATOR_PASS,
                    placeholder: $calculatorDefinition,
                    shouldLog: $shouldLog
                );
            } catch (Throwable $e) {
                $state->setInternalEventBehavior(
                    type: InternalEvent::CALCULATOR_FAIL,
                    placeholder: $calculatorDefinition,
                    payload: [
                        'error' => "Calculator failed: {$e->getMessage()}",
                    ],
                    shouldLog: $shouldLog
                );

                return false;
            }
        }

        return true;
    }

    // endregion
}
