<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scenarios;

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Actor\Machine;
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

        // Step 4: Persist scenario to DB
        if ($rootEventId !== null) {
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
     * Register scenario overrides in the container.
     * Called during execute() and during async restoration (§9).
     * Placeholder — will be implemented in plan-engine epic.
     */
    public static function registerOverrides(MachineScenario $scenario): void
    {
        // Placeholder for plan-engine epic
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
