<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scenarios;

use Illuminate\Support\Facades\App;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
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

    /** @var array<string, string|array> Delegation outcomes from classified plan (stateRoute => outcome). */
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
                    throw new MissingMachineContextException(
                        message: $e->getMessage()."\n\nHint: add a context override in the plan() for the relevant state.",
                        code: $e->getCode(),
                        previous: $e,
                    );
                }
            }

            // Step 8: @continue loop
            $maxDepth      = config('machine.max_transition_depth', 100);
            $continueCount = 0;

            while ($continueCount < $maxDepth) {
                $currentRoute = $this->resolveCurrentRoute($state);
                $continue     = $this->findContinueForState($currentRoute, $classified['overrides']);

                if ($continue === null) {
                    break;
                }

                $continueCount++;

                // Parse @continue value
                [$eventClass, $payload] = $this->parseContinueValue($continue);

                try {
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
            }

            // Step 9: Validate target
            $this->validateTarget($state);
        } finally {
            // Step 11: Cleanup — unbind overrides from container
            self::cleanupOverrides();
            self::$isActive = false;
        }

        return $state;
    }

    public static function isActive(): bool
    {
        return self::$isActive;
    }

    /**
     * Get the delegation outcome for a state route, if defined in plan().
     * Called by MachineDefinition during invoke handling.
     *
     * @return string|array|null Outcome string ('@done', '@fail', '@timeout') or array with 'outcome' key, or null.
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
    public static function executeChildScenario(
        string $childScenarioClass,
        string $childMachineClass,
        array $input = [],
    ): ?State {
        /** @var MachineScenario $childScenario */
        $childScenario = new $childScenarioClass();

        // Classify child plan values to populate outcomes/childScenarios for nested delegation
        $childPlan            = $childScenario->resolvedPlan();
        $parentOutcomes       = self::$outcomes;
        $parentChildScenarios = self::$childScenarios;

        // Register child's behavior overrides (adds to existing parent overrides)
        self::registerOverrides($childScenario);
        $childOutcomes       = [];
        $childChildScenarios = [];

        foreach ($childPlan as $stateRoute => $value) {
            if (is_string($value) && str_starts_with($value, '@')) {
                $childOutcomes[$stateRoute] = $value;
            } elseif (is_string($value) && class_exists($value) && is_subclass_of($value, MachineScenario::class)) {
                $childChildScenarios[$stateRoute] = $value;
            } elseif (is_array($value) && isset($value['outcome'])) {
                $childOutcomes[$stateRoute] = $value;
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

        // Restore parent outcomes (child overrides should not leak to parent)
        self::$outcomes       = $parentOutcomes;
        self::$childScenarios = $parentChildScenarios;

        // Check if child reached a final state
        if ($childState->currentStateDefinition?->type === StateDefinitionType::FINAL) {
            return $childState;
        }

        // Child paused at interactive/delegation state — stays running
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
    public static function registerOverrides(MachineScenario $scenario): void
    {
        $plan       = $scenario->resolvedPlan();
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
            public function __construct(private readonly bool $returnValue) {}

            public function __invoke(): bool
            {
                return $this->returnValue;
            }
        };
    }

    /**
     * Create an ActionBehavior proxy that writes key-value pairs to context.
     */
    private static function createContextWriteProxy(array $data): ActionBehavior
    {
        return new class($data) extends ActionBehavior {
            public function __construct(private readonly array $contextData) {}

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
    private static function createOutputProxy(array $data): OutputBehavior
    {
        return new class($data) extends OutputBehavior {
            public function __construct(private readonly array $outputData) {}

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
            public function __construct(private readonly \Closure $handler) {}

            public function __invoke(mixed ...$args): mixed
            {
                return ($this->handler)(...$args);
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
     *     overrides: array<string, array>,
     *     outcomes: array<string, string|array>,
     *     childScenarios: array<string, class-string<MachineScenario>>,
     * }
     */
    private function classifyPlanValues(): array
    {
        $plan           = $this->scenario->resolvedPlan();
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
        if (!config('machine.scenarios.enabled', false)) {
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
     * Get the current state route for @continue matching.
     * For simple machines, value has one entry like ['machine.state'].
     * For parallel, multiple entries — use the first for @continue matching.
     */
    private function resolveCurrentRoute(State $state): string
    {
        $routes = $state->value;

        return $routes[0] ?? '';
    }

    /**
     * Find @continue directive for the given state route.
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
            $fullRoute = str_contains((string) $stateRoute, '.') ? $stateRoute : '';
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
     *   'EventClass::class'                        → [EventClass, []]
     *   [EventClass::class, 'payload' => [...]]    → [EventClass, [...]]
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
