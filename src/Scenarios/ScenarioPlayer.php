<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scenarios;

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Exceptions\ScenariosDisabledException;
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
    public function execute(Machine $machine, array $eventPayload = []): State
    {
        // Step 1: Validate environment
        $this->validateEnvironment();

        // Steps 2-6: Placeholders for plan-engine and async epics

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
