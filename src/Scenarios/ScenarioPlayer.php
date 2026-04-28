<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scenarios;

use Illuminate\Support\Facades\App;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Behavior\OutputBehavior;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Testing\InlineBehaviorFake;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\ScenarioFailedException;
use Tarfinlabs\EventMachine\Exceptions\ScenariosDisabledException;
use Tarfinlabs\EventMachine\Exceptions\MissingMachineContextException;
use Tarfinlabs\EventMachine\Exceptions\ScenarioConfigurationException;
use Tarfinlabs\EventMachine\Exceptions\ScenarioTargetMismatchException;

/**
 * Runtime engine for scenario execution.
 * Validates environment, registers overrides, sends events, validates target.
 */
class ScenarioPlayer
{
    /** Whether a scenario is currently being executed. */
    private static bool $isActive = false;

    /** @var list<string> Inline behavior keys registered during this execution. */
    private static array $inlineKeys = [];

    /** @var list<string> Class-based behavior keys bound in container during this execution. */
    private static array $boundClassKeys = [];

    /** @var array<string, string|array<string, mixed>> Delegation outcomes from classified plan (stateRoute => outcome). */
    private static array $outcomes = [];

    /** @var array<string, class-string<MachineScenario>> Child scenario references from classified plan. */
    private static array $childScenarios = [];

    public function __construct(
        private readonly MachineScenario $scenario,
    ) {}

    /**
     * Execute the scenario on the given machine.
     *
     * Steps (from spec §10):
     * 1. Validate environment (scenarios enabled?)
     * 2. Validate and hydrate params → placeholder, done by controller
     * 3. Parse plan() → placeholder for plan-engine epic
     * 4. Persist scenario_class + scenario_params → placeholder for async epic
     * 5. Register behavior overrides → placeholder for plan-engine epic
     * 6. Prepare delegation handling → placeholder for plan-engine epic
     * 7. Send trigger event
     * 8. @continue loop → placeholder for plan-engine epic
     * 9. Validate target
     * 10. Build ScenarioOutput
     * 11. Cleanup → placeholder for plan-engine epic
     */
    /**
     * @param  array<string, mixed>  $eventPayload
     */
    public function execute(?Machine $machine = null, array $eventPayload = [], ?string $rootEventId = null): State
    {
        // Step 1: Validate environment
        $this->validateEnvironment();

        // Step 1b: Guard against Machine::fake() — scenarios require real execution
        $machineClass = $this->scenario->machine();
        if (method_exists($machineClass, 'isMachineFaked') && $machineClass::isMachineFaked()) {
            throw ScenarioConfigurationException::machineFaked($machineClass);
        }

        // Step 3: Parse plan() — validate state routes
        $this->validatePlanKeys($machineClass::definition());

        // Step 4: Persist scenario to DB (only when machine persists)
        if ($rootEventId !== null && $machine instanceof Machine && $this->shouldPersist($machine)) {
            $this->persistScenario($rootEventId);
        }

        // Steps 5-6: Register behavior overrides, populate outcomes, set active flag
        self::$isActive = true;

        try {
            self::registerOverrides($this->scenario);

            // Classify plan values to populate outcomes/childScenarios BEFORE send().
            // The engine queries these during handleMachineInvoke() to intercept delegations.
            $classified = $this->classifyPlanValues();

            // Step 7: Send trigger event (or create machine for @start scenarios)
            if ($this->scenario->event() === MachineScenario::START) {
                // @start: create a fresh machine — @always chain runs with overrides active.
                // Used for child machines with transient initial states (idle → @always → ...).
                $definition                = clone $machineClass::definition();
                $definition->shouldPersist = false;
                $definition->machineClass  = $machineClass;

                $machine = Machine::withDefinition($definition);
                $machine->start();
                $state = $machine->state;
            } else {
                if (!$machine instanceof Machine) {
                    throw ScenarioConfigurationException::missingProperty(
                        class: $this->scenario::class,
                        property: 'machine (no Machine instance provided and event is not @start)',
                    );
                }

                try {
                    $state = $machine->send([
                        'type'    => $this->scenario->eventType(),
                        'payload' => $eventPayload,
                    ]);
                } catch (MissingMachineContextException $e) {
                    $contextHints = $this->buildRequiredContextHints($machine);

                    throw new MissingMachineContextException(
                        message: $e->getMessage()."\n\n".$contextHints,
                        code: $e->getCode(),
                        previous: $e,
                    );
                }
            }

            // Step 8: @continue loop
            $maxDepth      = config('machine.max_transition_depth', 100);
            $continueCount = 0;
            $startIndex    = 0;

            while ($continueCount < $maxDepth) {
                $match = $this->findActiveContinue($state, $classified['overrides'], $startIndex);
                if ($match === null) {
                    break;
                }
                [$matchedIdx, $currentRoute, $continue] = $match;

                $continueCount++;

                // Parse @continue value
                [$eventClass, $rawPayload] = $this->parseContinueValue($continue);

                $prevValue = $state->value;

                try {
                    $payload = $this->resolveContinuePayload($rawPayload, $state);

                    $state = $machine->send([
                        'type'    => $this->resolveEventType($eventClass),
                        'payload' => $payload,
                    ]);
                } catch (\Throwable $e) {
                    throw ScenarioFailedException::continueEventFailed(
                        state: $currentRoute,
                        event: $eventClass,
                        reason: $e->getMessage(),
                    );
                }

                // No-progress break: a guarded @continue whose guard fails
                // would otherwise re-fire forever from the same route. If
                // send() did not advance the active configuration, stop.
                if ($state->value === $prevValue) {
                    break;
                }

                // Round-robin start point: next iteration begins at the
                // route after the one that just fired, so each region of
                // a parallel state gets its turn before we cycle back.
                $startIndex = $matchedIdx + 1;
            }

            // Step 9: Validate target
            $this->validateTarget($state);

            // Step 10: Re-persist scenario after state changes
            // syncCurrentStates deletes old rows and creates new ones without scenario columns.
            // Re-persist so activeScenario is visible in buildResponse and continuation persists.
            if ($rootEventId !== null && $rootEventId !== '' && $this->shouldPersist($machine)) {
                $this->persistScenario($rootEventId);
            }
        } finally {
            // Step 11: Cleanup — unbind overrides from container
            self::cleanupOverrides();
            self::$isActive = false;
        }

        return $state;
    }

    /**
     * Execute Phase 2 (continuation) — applies continuation overrides for subsequent requests.
     *
     * Unlike execute(): no source/target validation, accepts any event type,
     * deactivates on final state, keeps scenario active on interactive state.
     *
     * @param  array<string, mixed>  $eventPayload
     */
    public function executeContinuation(
        Machine $machine,
        array $eventPayload,
        string $rootEventId,
        string $eventType,
    ): State {
        self::$isActive = true;

        try {
            self::registerOverrides($this->scenario, useContinuation: true);
            $classified = $this->classifyPlanValues(useContinuation: true);

            // Send the event (whatever QA sent — not the scenario's declared event)
            $state = $machine->send([
                'type'    => $eventType,
                'payload' => $eventPayload,
            ]);

            // @continue loop — same logic as execute()
            $continueCount = 0;
            $maxDepth      = (int) config('machine.max_transition_depth', 100);
            $startIndex    = 0;

            while ($continueCount < $maxDepth) {
                $match = $this->findActiveContinue($state, $classified['overrides'], $startIndex);
                if ($match === null) {
                    break;
                }
                [$matchedIdx, , $continue] = $match;

                $continueCount++;
                [$eventClass, $rawPayload] = $this->parseContinueValue($continue);
                $payload                   = $this->resolveContinuePayload($rawPayload, $state);

                $prevValue = $state->value;

                $state = $machine->send([
                    'type'    => $this->resolveEventType($eventClass),
                    'payload' => $payload,
                ]);

                if ($state->value === $prevValue) {
                    break;
                }

                $startIndex = $matchedIdx + 1;
            }

            // Deactivate if final state reached, otherwise re-persist scenario
            // (syncCurrentStates deletes old rows and creates new ones without scenario columns)
            if ($state->currentStateDefinition->type === StateDefinitionType::FINAL) {
                self::deactivateScenario($rootEventId);
            } elseif ($rootEventId !== '' && $this->shouldPersist($machine)) {
                $this->persistScenario($rootEventId);
            }

            return $state;
        } finally {
            self::cleanupOverrides();
            self::$isActive = false;
        }
    }

    public static function isActive(): bool
    {
        return self::$isActive;
    }

    /**
     * Activate scenario context for an async-boot worker process (e.g. ChildMachineJob).
     *
     * Restores the same three-step activation that play() performs (isActive=true,
     * registered behavior overrides, classified outcomes/childScenarios), so a child
     * machine started in a fresh queue worker process applies its scenario plan
     * exactly as a sync run does.
     *
     * Callers are responsible for invoking deactivate() when done — typically in
     * a finally block — so static state does not leak across queue jobs in a
     * long-lived worker process.
     */
    public static function activateForAsyncBoot(MachineScenario $scenario): void
    {
        // Populate self::$outcomes and self::$childScenarios from the scenario plan.
        // Mirrors classifyPlanValues() but lives in a static context that does not
        // require a constructed ScenarioPlayer instance.
        $plan           = $scenario->resolvedPlan();
        $outcomes       = [];
        $childScenarios = [];

        foreach ($plan as $stateRoute => $value) {
            if (is_string($value) && str_starts_with($value, '@')) {
                $outcomes[$stateRoute] = $value;
            } elseif (is_string($value) && class_exists($value) && is_subclass_of($value, MachineScenario::class)) {
                $childScenarios[$stateRoute] = $value;
            } elseif (is_array($value) && isset($value['outcome'])) {
                $outcomes[$stateRoute] = $value;
            }
        }

        self::$outcomes       = $outcomes;
        self::$childScenarios = $childScenarios;

        // Bind behavior overrides (Guard/Action class fakes, inline keys).
        self::registerOverrides($scenario);

        self::$isActive = true;
    }

    /**
     * Tear down scenario context after an async-boot worker finishes its work.
     *
     * Mirrors the cleanup performed by play()'s finally block. MUST be called in a
     * finally block by callers of activateForAsyncBoot() to prevent static state
     * leaking between queue jobs in the same worker process.
     */
    public static function deactivate(): void
    {
        self::cleanupOverrides();
        self::$isActive = false;
    }

    /**
     * Get the delegation outcome for a state route, if defined in plan().
     * Called by MachineDefinition during invoke handling.
     *
     * @return string|array<string, mixed>|null Outcome string ('@done', '@fail', '@timeout') or array with 'outcome' key, or null.
     */
    public static function getOutcome(string $stateRoute): string|array|null
    {
        // Try exact match first
        if (isset(self::$outcomes[$stateRoute])) {
            return self::$outcomes[$stateRoute];
        }

        // Try suffix match (plan keys may omit machine prefix)
        foreach (self::$outcomes as $route => $outcome) {
            if (str_ends_with($stateRoute, '.'.$route)) {
                return $outcome;
            }
        }

        return null;
    }

    /**
     * Get the child scenario class for a state route, if defined in plan().
     * Called by MachineDefinition during invoke handling.
     */
    public static function getChildScenario(string $stateRoute): ?string
    {
        if (isset(self::$childScenarios[$stateRoute])) {
            return self::$childScenarios[$stateRoute];
        }

        foreach (self::$childScenarios as $route => $scenarioClass) {
            if (str_ends_with($stateRoute, '.'.$route)) {
                return $scenarioClass;
            }
        }

        return null;
    }

    /**
     * Reset all inline behavior overrides registered during scenario execution.
     * Called at the end of execute() and by test teardown.
     */
    public static function resetInlineOverrides(): void
    {
        foreach (self::$inlineKeys as $key) {
            InlineBehaviorFake::reset($key);
        }

        self::$inlineKeys = [];
    }

    /**
     * Unbind all scenario overrides from the container.
     * Called in execute()'s finally block — ensures overrides never leak.
     */
    public static function cleanupOverrides(): void
    {
        // Unbind class-based overrides
        foreach (self::$boundClassKeys as $key) {
            app()->offsetUnset($key);
        }

        self::$boundClassKeys = [];

        // Clear delegation outcomes and child scenario references
        self::$outcomes       = [];
        self::$childScenarios = [];

        // Reset inline overrides
        self::resetInlineOverrides();
    }

    /**
     * Clear scenario from machine_current_states.
     * Called when QA sends next event without scenario field.
     */
    public static function deactivateScenario(string $rootEventId): void
    {
        MachineCurrentState::where('root_event_id', $rootEventId)
            ->update([
                'scenario_class'  => null,
                'scenario_params' => null,
            ]);
    }

    /**
     * Execute a child scenario — creates child machine, applies overrides, starts.
     * Returns the child's final state or null if child paused at interactive state.
     *
     * The child machine is created with shouldPersist=false to avoid DB dependency
     * during scenario replay. Child's plan() overrides (guards, outcomes) and the
     * parent's existing overrides are both active in the container.
     */
    /**
     * @param  array<string, mixed>  $input
     */
    public static function executeChildScenario(
        string $childScenarioClass,
        string $childMachineClass,
        array $input = [],
        ?string $parentRootEventId = null,
        ?string $parentMachineClass = null,
        ?string $parentStateId = null,
    ): ?State {
        /** @var MachineScenario $childScenario */
        $childScenario = new $childScenarioClass();

        // Save parent state — restored after child completes
        $childPlan            = $childScenario->resolvedPlan();
        $parentOutcomes       = self::$outcomes;
        $parentChildScenarios = self::$childScenarios;

        // Register child's behavior overrides (adds to existing parent overrides)
        self::registerOverrides($childScenario);

        // Classify child plan into outcomes, child scenarios, and overrides (with @continue)
        // Inlined to avoid classifyPlanValues() side effect on self::$outcomes
        $childOutcomes       = [];
        $childChildScenarios = [];
        $childOverrides      = [];

        foreach ($childPlan as $stateRoute => $value) {
            if (is_string($value) && str_starts_with($value, '@')) {
                $childOutcomes[$stateRoute] = $value;
            } elseif (is_string($value) && class_exists($value) && is_subclass_of($value, MachineScenario::class)) {
                $childChildScenarios[$stateRoute] = $value;
            } elseif (is_array($value) && isset($value['outcome'])) {
                $childOutcomes[$stateRoute] = $value;

                // Extract behavior overrides from outcome array
                $behaviorKeys = array_filter(
                    $value,
                    fn (mixed $v, int|string $k): bool => is_string($k) && $k !== 'outcome' && $k !== 'output' && class_exists($k),
                    ARRAY_FILTER_USE_BOTH,
                );

                if ($behaviorKeys !== []) {
                    $childOverrides[$stateRoute] = $behaviorKeys;
                }
            } elseif (is_array($value)) {
                $childOverrides[$stateRoute] = $value;
            }
        }

        // Merge child outcomes into static storage (child takes precedence)
        self::$outcomes       = array_merge(self::$outcomes, $childOutcomes);
        self::$childScenarios = array_merge(self::$childScenarios, $childChildScenarios);

        // Create child machine without persistence — @always chain runs with overrides
        $definition                = clone $childMachineClass::definition();
        $definition->shouldPersist = false;
        $definition->machineClass  = $childMachineClass;

        if ($input !== []) {
            $definition->config['context'] = array_merge(
                $definition->config['context'] ?? [],
                $input,
            );
        }

        $childMachine = Machine::withDefinition($definition);
        $childMachine->start();

        $childState = $childMachine->state;

        // @continue loop — advance through interactive states (child outcomes still active)
        $childPlayer   = new self($childScenario);
        $continueCount = 0;
        $maxDepth      = (int) config('machine.max_transition_depth', 100);

        $startIndex = 0;
        while ($continueCount < $maxDepth) {
            $match = $childPlayer->findActiveContinue($childState, $childOverrides, $startIndex);
            if ($match === null) {
                break;
            }
            [$matchedIdx, $currentRoute, $continue] = $match;

            $continueCount++;
            [$eventClass, $rawPayload] = $childPlayer->parseContinueValue($continue);

            $prevValue = $childState->value;

            try {
                $payload = $childPlayer->resolveContinuePayload($rawPayload, $childState);

                $childState = $childMachine->send([
                    'type'    => $childPlayer->resolveEventType($eventClass),
                    'payload' => $payload,
                ]);
            } catch (\Throwable $e) {
                // Restore parent outcomes before re-throwing
                self::$outcomes       = $parentOutcomes;
                self::$childScenarios = $parentChildScenarios;

                throw ScenarioFailedException::continueEventFailed(
                    state: $currentRoute,
                    event: $eventClass,
                    reason: $e->getMessage(),
                );
            }

            if ($childState->value === $prevValue) {
                break;
            }

            $startIndex = $matchedIdx + 1;
        }

        // Restore parent outcomes AFTER loop completes (child outcomes no longer needed)
        self::$outcomes       = $parentOutcomes;
        self::$childScenarios = $parentChildScenarios;

        // Check if child reached a final state
        if ($childState->currentStateDefinition?->type === StateDefinitionType::FINAL) {
            return $childState;
        }

        // Child paused at interactive/delegation state — persist for forward endpoint access
        if ($parentRootEventId !== null && $parentMachineClass !== null && $parentStateId !== null) {
            $childMachine->definition->shouldPersist = true;
            $childMachine->persist();

            $childRootEventId = $childMachine->state->history->first()?->root_event_id;

            if ($childRootEventId !== null) {
                MachineChild::create([
                    'parent_root_event_id' => $parentRootEventId,
                    'parent_machine_class' => $parentMachineClass,
                    'parent_state_id'      => $parentStateId,
                    'child_root_event_id'  => $childRootEventId,
                    'child_machine_class'  => $childMachineClass,
                    'status'               => MachineChild::STATUS_RUNNING,
                    'created_at'           => now(),
                ]);

                // Persist scenario for subsequent restorations.
                // Outcome-only scenarios (no @continue) still need the row populated
                // so async dispatch + restore paths can re-activate the scenario context.
                MachineCurrentState::where('root_event_id', $childRootEventId)
                    ->update([
                        'scenario_class'  => $childScenarioClass,
                        'scenario_params' => null,
                    ]);
            }
        }

        return null;
    }

    /**
     * Register behavior overrides in the container.
     * Called during execute() and during async restoration (§9).
     *
     * When the same behavior key appears in multiple plan() states with DIFFERENT
     * values, detectStateAwareOverrides() identifies the conflict. For now, the
     * last state's value is used (simple last-wins policy). True state-aware
     * routing at invocation time requires engine support and is deferred.
     */
    public static function registerOverrides(MachineScenario $scenario, bool $useContinuation = false): void
    {
        $plan       = $useContinuation ? $scenario->resolvedContinuation() : $scenario->resolvedPlan();
        $stateAware = self::detectStateAwareOverrides($plan);

        foreach ($plan as $value) {
            if (!is_array($value)) {
                continue; // Simple string values (delegation outcomes, child scenario refs) — skip
            }

            if (isset($value['outcome'])) {
                // Delegation outcome with guard overrides — extract overrides, skip meta-keys
                foreach ($value as $key => $override) {
                    if ($key === 'outcome') {
                        continue;
                    }
                    if ($key === 'output') {
                        continue;
                    }
                    // Skip conflicting keys — handled below with last-value policy
                    if (isset($stateAware[$key])) {
                        continue;
                    }
                    self::bindOverride($key, $override);
                }

                continue;
            }

            // Behavior overrides array
            foreach ($value as $behaviorKey => $override) {
                if ($behaviorKey === '@continue') {
                    continue; // @continue is not a behavior override
                }
                // Skip conflicting keys — handled below with last-value policy
                if (isset($stateAware[$behaviorKey])) {
                    continue;
                }

                self::bindOverride($behaviorKey, $override);
            }
        }

        // State-aware overrides: bind the last state's value.
        // When the same behavior appears in multiple states with different values,
        // the last occurrence in plan() order wins. Full per-invocation state routing
        // is deferred until the engine exposes current state context during behavior resolution.
        foreach ($stateAware as $key => $stateOverrides) {
            $lastOverride = end($stateOverrides);
            self::bindOverride($key, $lastOverride);
        }
    }

    /**
     * Detect same-behavior-different-values across states.
     *
     * Returns a map of behaviorKey → [stateRoute => override] for behavior keys
     * that appear in multiple plan() states with differing values.
     *
     * @param  array<string, mixed>  $plan
     *
     * @return array<string, array<string, mixed>>
     */
    private static function detectStateAwareOverrides(array $plan): array
    {
        $behaviorStates = []; // behaviorKey → [stateRoute => override]

        foreach ($plan as $stateRoute => $value) {
            if (!is_array($value)) {
                continue;
            }
            if (isset($value['outcome'])) {
                continue;
            }
            foreach ($value as $key => $override) {
                if ($key === '@continue') {
                    continue;
                }
                $behaviorStates[$key][$stateRoute] = $override;
            }
        }

        // Only return keys that appear in multiple states with different values
        $conflicts = [];

        foreach ($behaviorStates as $key => $stateOverrides) {
            if (count($stateOverrides) <= 1) {
                continue;
            }

            // Check if all values are identical
            $values  = array_values($stateOverrides);
            $allSame = true;
            $counter = count($values);

            for ($i = 1; $i < $counter; $i++) {
                if ($values[$i] !== $values[0]) {
                    $allSame = false;
                    break;
                }
            }

            if (!$allSame) {
                $conflicts[$key] = $stateOverrides;
            }
        }

        return $conflicts;
    }

    /**
     * Bind a single behavior override in the container.
     */
    private static function bindOverride(string $behaviorKey, mixed $override): void
    {
        // Class-based behaviors (FQCN extending InvokableBehavior) — use App::bind()
        if (class_exists($behaviorKey) && is_subclass_of($behaviorKey, InvokableBehavior::class)) {
            self::bindClassOverride($behaviorKey, $override);

            return;
        }

        // Inline behavior keys (camelCase strings) — use InlineBehaviorFake
        self::bindInlineOverride($behaviorKey, $override);
    }

    /**
     * Bind a class-based behavior override via App::bind().
     */
    private static function bindClassOverride(string $behaviorKey, mixed $override): void
    {
        self::$boundClassKeys[] = $behaviorKey;

        App::bind($behaviorKey, function () use ($behaviorKey, $override) {
            return match (true) {
                is_bool($override)                                                                                     => self::createBoolGuardProxy($override),
                is_array($override) && is_subclass_of($behaviorKey, OutputBehavior::class)                             => self::createOutputProxy($override),
                is_array($override)                                                                                    => self::createContextWriteProxy($override),
                $override instanceof \Closure                                                                          => self::createClosureProxy($override),
                is_string($override) && class_exists($override) && is_subclass_of($override, InvokableBehavior::class) => App::make($override),
                default                                                                                                => throw new \InvalidArgumentException("Invalid override value for {$behaviorKey}"),
            };
        });
    }

    /**
     * Bind an inline behavior key override via InlineBehaviorFake.
     */
    private static function bindInlineOverride(string $key, mixed $override): void
    {
        if (is_bool($override)) {
            InlineBehaviorFake::fake($key, fn (): bool => $override);
        } elseif (is_array($override)) {
            InlineBehaviorFake::fake($key, function (ContextManager $ctx) use ($override): void {
                foreach ($override as $k => $v) {
                    $ctx->set($k, $v);
                }
            });
        } elseif ($override instanceof \Closure) {
            InlineBehaviorFake::fake($key, $override);
        }

        self::$inlineKeys[] = $key;
    }

    /**
     * Create a GuardBehavior proxy that returns a fixed bool.
     */
    private static function createBoolGuardProxy(bool $value): GuardBehavior
    {
        return new class($value) extends GuardBehavior {
            public function __construct(private readonly bool $returnValue)
            {
                parent::__construct();
            }

            public function __invoke(): bool
            {
                return $this->returnValue;
            }
        };
    }

    /**
     * Create an ActionBehavior proxy that writes key-value pairs to context.
     */
    /**
     * @param  array<string, mixed>  $data
     */
    private static function createContextWriteProxy(array $data): ActionBehavior
    {
        return new class($data) extends ActionBehavior {
            /**
             * @param  array<string, mixed>  $contextData
             */
            public function __construct(private readonly array $contextData)
            {
                parent::__construct();
            }

            public function __invoke(ContextManager $ctx): void
            {
                foreach ($this->contextData as $key => $val) {
                    $ctx->set($key, $val);
                }
            }
        };
    }

    /**
     * Create an OutputBehavior proxy that returns a fixed array.
     */
    /**
     * @param  array<string, mixed>  $data
     */
    private static function createOutputProxy(array $data): OutputBehavior
    {
        return new class($data) extends OutputBehavior {
            /**
             * @param  array<string, mixed>  $outputData
             */
            public function __construct(private readonly array $outputData)
            {
                parent::__construct();
            }

            /**
             * @return array<string, mixed>
             */
            public function __invoke(): array
            {
                return $this->outputData;
            }
        };
    }

    /**
     * Create an InvokableBehavior proxy that delegates to a closure.
     */
    private static function createClosureProxy(\Closure $handler): InvokableBehavior
    {
        return new class($handler) extends InvokableBehavior {
            /** @var \Closure Exposed for injectInvokableBehaviorParameters — reflects original closure's type hints */
            public readonly \Closure $scenarioHandler;

            public function __construct(\Closure $handler)
            {
                parent::__construct();
                $this->scenarioHandler = $handler;
            }

            public function __invoke(mixed ...$args): mixed
            {
                return ($this->scenarioHandler)(...$args);
            }
        };
    }

    /**
     * Validate all plan() keys exist as state routes in the machine definition.
     */
    private function validatePlanKeys(MachineDefinition $definition): void
    {
        $plan = $this->scenario->resolvedPlan();

        foreach (array_keys($plan) as $stateRoute) {
            // Try full ID first (machine.state.path)
            $found = $definition->idMap[$stateRoute] ?? null;

            // Try with machine prefix
            if ($found === null) {
                $found = $definition->idMap[$definition->id.'.'.$stateRoute] ?? null;
            }

            if ($found === null) {
                throw ScenarioConfigurationException::invalidStateRoute(
                    route: $stateRoute,
                    machineClass: $this->scenario->machine(),
                );
            }
        }
    }

    /**
     * Persist scenario class and params to machine_current_states.
     */
    private function persistScenario(string $rootEventId): void
    {
        MachineCurrentState::where('root_event_id', $rootEventId)
            ->update([
                'scenario_class'  => $this->scenario::class,
                'scenario_params' => $this->scenario->validatedParams() !== [] ? $this->scenario->validatedParams() : null,
            ]);
    }

    private function shouldPersist(Machine $machine): bool
    {
        return $machine->definition->shouldPersist;
    }

    /**
     * Classify each plan() value using the detection table (spec §5).
     *
     * @return array{
     *     overrides: array<string, array<string, mixed>>,
     *     outcomes: array<string, string|array<string, mixed>>,
     *     childScenarios: array<string, class-string<MachineScenario>>,
     * }
     */
    private function classifyPlanValues(bool $useContinuation = false): array
    {
        $plan           = $useContinuation ? $this->scenario->resolvedContinuation() : $this->scenario->resolvedPlan();
        $overrides      = [];
        $outcomes       = [];
        $childScenarios = [];

        foreach ($plan as $stateRoute => $value) {
            if (is_string($value) && str_starts_with($value, '@')) {
                // Delegation outcome — simple string (@done, @fail, @timeout)
                $outcomes[$stateRoute] = $value;
            } elseif (is_string($value) && class_exists($value) && is_subclass_of($value, MachineScenario::class)) {
                // Child scenario reference
                $childScenarios[$stateRoute] = $value;
            } elseif (is_array($value) && isset($value['outcome'])) {
                // Delegation outcome with optional output and/or guard overrides
                $outcomes[$stateRoute] = $value;

                // Extract behavior overrides from outcome array (guard/action class keys)
                $behaviorKeys = array_filter(
                    $value,
                    fn (mixed $v, int|string $k): bool => is_string($k) && $k !== 'outcome' && $k !== 'output' && class_exists($k),
                    ARRAY_FILTER_USE_BOTH,
                );

                if ($behaviorKeys !== []) {
                    $overrides[$stateRoute] = array_merge($overrides[$stateRoute] ?? [], $behaviorKeys);
                }
            } elseif (is_array($value)) {
                // Behavior overrides (may include @continue)
                $overrides[$stateRoute] = $value;
            }
        }

        // Populate static storage for engine to query during delegation handling
        self::$outcomes       = $outcomes;
        self::$childScenarios = $childScenarios;

        return [
            'overrides'      => $overrides,
            'outcomes'       => $outcomes,
            'childScenarios' => $childScenarios,
        ];
    }

    private function validateEnvironment(): void
    {
        if (!(bool) config('machine.scenarios.enabled', false)) {
            throw ScenariosDisabledException::disabled();
        }
    }

    private function validateTarget(State $state): void
    {
        $currentRoutes = $state->value;
        $target        = $this->scenario->target();

        // Check if any current state route matches the target.
        // For parallel states, child routes contain the parent as a prefix
        // (e.g., target 'data_collection' matches 'car_sales.data_collection.retailer.waiting').
        foreach ($currentRoutes as $route) {
            if ($route === $target || str_ends_with($route, '.'.$target)) {
                return;
            }
            // Parallel: target is parent, route is child (contains target as segment)
            if (str_contains($route, '.'.$target.'.')) {
                return;
            }
        }

        throw ScenarioTargetMismatchException::mismatch(
            expected: $target,
            actual: implode(', ', $currentRoutes),
        );
    }

    /**
     * Find the next @continue match across all active state routes,
     * starting from $startIndex and wrapping round-robin.
     *
     * In parallel states, $state->value contains one entry per active region.
     * The round-robin start point makes each region's @continue fire in turn
     * across iterations, so a region whose leaf has a @continue can never
     * starve other regions. Without it, $state->value[0] always wins and
     * the second region is silently skipped — see the "Parallel @continue"
     * section in scenario-plan.md.
     *
     * For simple (non-parallel) machines, $state->value has one entry, so
     * the round-robin is a no-op and this behaves like a single lookup.
     *
     * @param  array<string, array<string, mixed>>  $overrides
     *
     * @return array{0: int, 1: string, 2: mixed}|null [routeIndex, route, @continue]
     */
    private function findActiveContinue(State $state, array $overrides, int $startIndex = 0): ?array
    {
        $routes = array_values($state->value);
        $count  = count($routes);
        if ($count === 0) {
            return null;
        }

        for ($offset = 0; $offset < $count; $offset++) {
            $idx      = ($startIndex + $offset) % $count;
            $route    = $routes[$idx];
            $continue = $this->findContinueForState($route, $overrides);
            if ($continue !== null) {
                return [$idx, $route, $continue];
            }
        }

        return null;
    }

    /**
     * Find @continue directive for the given state route.
     */
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function findContinueForState(string $currentRoute, array $overrides): mixed
    {
        foreach ($overrides as $stateRoute => $value) {
            if (!is_array($value)) {
                continue;
            }
            if (!isset($value['@continue'])) {
                continue;
            }
            // Match: exact route or suffix match
            $fullRoute = str_contains($stateRoute, '.') ? $stateRoute : '';
            if ($currentRoute === $stateRoute
                || str_ends_with($currentRoute, '.'.$stateRoute)
                || $currentRoute === $fullRoute) {
                return $value['@continue'];
            }
        }

        return null;
    }

    /**
     * Parse @continue value into [eventClass, payload].
     *
     * Formats:
     *   'EventClass::class'                            → [EventClass, []]
     *   [EventClass::class, 'payload' => [...]]        → [EventClass, [...]]
     *   [EventClass::class, 'payload' => fn(...) => [...]] → [EventClass, Closure]
     *
     * Closure payloads are returned unresolved — call resolveContinuePayload()
     * with the current State to invoke them with InvokableBehavior parameter
     * injection (ContextManager, State, EventBehavior, EventCollection).
     */
    /**
     * @return array{0: string, 1: array<string, mixed>|\Closure}
     */
    private function parseContinueValue(mixed $continue): array
    {
        if (is_string($continue)) {
            return [$continue, []];
        }

        if (is_array($continue)) {
            $eventClass = $continue[0] ?? $continue[array_key_first($continue)];
            $payload    = $continue['payload'] ?? [];

            return [$eventClass, $payload];
        }

        return [(string) $continue, []];
    }

    /**
     * Resolve a @continue payload — invokes Closure payloads with parameter
     * injection (matches Callable Outcome semantics in delegation states).
     *
     * Runs at @continue dispatch time, after the previous transition has
     * populated context. The Closure must return an array<string, mixed>.
     */
    /**
     * @param  array<string, mixed>|\Closure  $payload
     *
     * @return array<string, mixed>
     */
    private function resolveContinuePayload(mixed $payload, State $state): array
    {
        if ($payload instanceof \Closure) {
            $parameters = InvokableBehavior::injectInvokableBehaviorParameters(
                actionBehavior: $payload,
                state: $state,
                eventBehavior: $state->triggeringEvent,
            );

            $resolved = $payload(...$parameters);

            if (!is_array($resolved)) {
                throw ScenarioConfigurationException::invalidContinuePayloadClosure(
                    actualType: get_debug_type($resolved),
                );
            }

            return $resolved;
        }

        return $payload;
    }

    /**
     * Build detailed required context hints from behaviors at the current state.
     * Inspects $requiredContext static property on InvokableBehavior subclasses
     * used in the current state's transitions and entry actions.
     */
    private function buildRequiredContextHints(Machine $machine): string
    {
        $state           = $machine->state;
        $stateDefinition = $state->currentStateDefinition;

        if ($stateDefinition === null) {
            return 'Hint: add a context override in the plan() for the relevant state.';
        }

        $hints     = [];
        $behaviors = [];

        // Entry actions
        foreach ($stateDefinition->entry ?? [] as $entryDef) {
            $action = is_string($entryDef) ? $entryDef : ($entryDef['action'] ?? null);
            if ($action !== null && is_string($action) && is_subclass_of($action, InvokableBehavior::class)) {
                $behaviors[$action] = 'entry action';
            }
        }

        // Transition guards and actions
        foreach ($stateDefinition->transitionDefinitions ?? [] as $transition) {
            foreach ($transition->branches ?? [] as $branch) {
                foreach ($branch->guards ?? [] as $guard) {
                    if (is_string($guard) && is_subclass_of($guard, InvokableBehavior::class)) {
                        $behaviors[$guard] = 'guard';
                    }
                }
                foreach ($branch->actions ?? [] as $action) {
                    if (is_subclass_of($action, InvokableBehavior::class)) {
                        $behaviors[$action] = 'action';
                    }
                }
            }
        }

        // Check $requiredContext on each behavior
        foreach ($behaviors as $behaviorClass => $type) {
            /** @var class-string<InvokableBehavior> $behaviorClass */
            if ($behaviorClass::$requiredContext !== []) {
                $fields = [];
                foreach ($behaviorClass::$requiredContext as $key => $typeHint) {
                    $fields[] = is_string($key) ? "{$key} ({$typeHint})" : $typeHint;
                }
                $hints[] = '  → '.class_basename($behaviorClass)." ({$type}): ".implode(', ', $fields);
            }
        }

        if ($hints === []) {
            return 'Hint: add a context override in the plan() for the relevant state.';
        }

        return "Required context for behaviors at '{$stateDefinition->route}':\n".implode("\n", $hints)."\n\nHint: add overrides for these behaviors in your plan().";
    }

    /**
     * Resolve an event class FQCN to its event type string.
     * If it's an EventBehavior subclass, calls getType(). Otherwise returns as-is.
     */
    private function resolveEventType(string $event): string
    {
        if (class_exists($event) && method_exists($event, 'getType')) {
            return $event::getType();
        }

        return $event;
    }
}
