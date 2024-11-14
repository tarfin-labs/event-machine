<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

use Throwable;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Enums\BehaviorType;
use Tarfinlabs\EventMachine\Enums\InternalEvent;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;
use Tarfinlabs\EventMachine\Enums\TransitionProperty;
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;
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

        $this->description = $this->transitionConfig['description'] ?? null;

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

            $guardsPassed = true;
            foreach ($branch->guards as $guardDefinition) {
                [$guardDefinition, $guardArguments] = array_pad(explode(':', $guardDefinition, 2), 2, null);
                $guardArguments                     = $guardArguments === null ? [] : explode(',', $guardArguments);

                $guardBehavior = $this->source->machine->getInvokableBehavior(
                    behaviorDefinition: $guardDefinition,
                    behaviorType: BehaviorType::Guard
                );

                $shouldLog = $guardBehavior?->shouldLog ?? false;

                if ($guardBehavior instanceof GuardBehavior) {
                    $guardBehavior->validateRequiredContext($state->context);
                }

                // Inject guard behavior parameters
                $guardBehaviorParameters = InvokableBehavior::injectInvokableBehaviorParameters(
                    actionBehavior: $guardBehavior,
                    state: $state,
                    eventBehavior: $eventBehavior,
                    actionArguments: $guardArguments,
                );

                // Execute the guard behavior
                $guardResult = ($guardBehavior)(...$guardBehaviorParameters);

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

            if ($guardsPassed === true) {
                return $branch;
            }
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
        if (!isset($branch->calculators)) {
            return true;
        }

        foreach ($branch->calculators as $calculatorDefinition) {
            [$calculatorDefinition, $calculatorArguments] = array_pad(explode(':', $calculatorDefinition, 2), 2, null);
            $calculatorArguments                          = $calculatorArguments === null ? [] : explode(',', $calculatorArguments);

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
                );

                ($calculatorBehavior)(...$calculatorParameters);

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
