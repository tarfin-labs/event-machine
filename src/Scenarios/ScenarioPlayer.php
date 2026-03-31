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
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\ScenariosDisabledException;
use Tarfinlabs\EventMachine\Exceptions\ScenarioConfigurationException;
use Tarfinlabs\EventMachine\Exceptions\ScenarioTargetMismatchException;

/**
 * Runtime engine for scenario execution.
 * Validates environment, registers overrides, sends events, validates target.
 */
class ScenarioPlayer
{
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
    public function execute(Machine $machine, array $eventPayload = [], ?string $rootEventId = null): State
    {
        // Step 1: Validate environment
        $this->validateEnvironment();

        // Step 3: Parse plan() — validate state routes
        $this->validatePlanKeys($this->scenario->machine()::definition());

        // Step 3b: Classify plan() values
        $this->classifyPlanValues();

        // Step 4: Persist scenario to DB (only when machine persists)
        if ($rootEventId !== null && $this->shouldPersist($machine)) {
            $this->persistScenario($rootEventId);
        }

        // Steps 5-6: Placeholders for plan-engine and async epics

        // Step 7: Send trigger event
        $state = $machine->send([
            'type'    => $this->scenario->event(),
            'payload' => $eventPayload,
        ]);

        // Step 8: @continue loop — placeholder

        // Step 9: Validate target
        $this->validateTarget($state);

        return $state;
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
     * Register behavior overrides in the container.
     * Called during execute() and during async restoration (§9).
     */
    public static function registerOverrides(MachineScenario $scenario): void
    {
        $plan = $scenario->resolvedPlan();

        foreach ($plan as $value) {
            if (!is_array($value) || isset($value['outcome'])) {
                continue; // Skip delegation outcomes and child scenarios
            }

            foreach ($value as $behaviorKey => $override) {
                if ($behaviorKey === '@continue') {
                    continue; // @continue is not a behavior override
                }

                self::bindOverride($behaviorKey, $override);
            }
        }
    }

    /**
     * Bind a single behavior override in the container.
     */
    private static function bindOverride(string $behaviorKey, mixed $override): void
    {
        // Only bind class-based behaviors (FQCN that extend InvokableBehavior)
        if (!class_exists($behaviorKey) || !is_subclass_of($behaviorKey, InvokableBehavior::class)) {
            // Inline behavior keys (camelCase strings) are handled by InlineBehaviorFake
            return;
        }

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

        // Check if any current state route matches or contains the target
        foreach ($currentRoutes as $route) {
            if ($route === $target || str_contains($route, '.'.$target) || str_ends_with($route, '.'.$target)) {
                return;
            }
        }

        throw ScenarioTargetMismatchException::mismatch(
            expected: $target,
            actual: implode(', ', $currentRoutes),
        );
    }
}
