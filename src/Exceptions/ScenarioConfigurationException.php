<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use LogicException;

/**
 * Thrown for structural/config errors in scenario definitions.
 */
class ScenarioConfigurationException extends LogicException
{
    public static function missingProperty(string $class, string $property): self
    {
        return new self(
            message: "Scenario '{$class}' is missing required property: \${$property}"
        );
    }

    public static function invalidStateRoute(string $route, string $machineClass): self
    {
        return new self(
            message: "State route '{$route}' not found in {$machineClass} definition"
        );
    }

    public static function delegationOutcomeOnNonDelegationState(string $route): self
    {
        return new self(
            message: "State '{$route}' has delegation outcome but is not a delegation state"
        );
    }

    public static function behaviorOverrideOnDelegationState(string $route): self
    {
        return new self(
            message: "State '{$route}' has behavior override but is a delegation state — use delegation outcomes"
        );
    }

    public static function continueOnDelegationState(string $route): self
    {
        return new self(
            message: "State '{$route}' has @continue but is a delegation state"
        );
    }

    public static function childScenarioMachineMismatch(string $scenarioClass, string $expectedMachine, string $actualMachine): self
    {
        return new self(
            message: "Child scenario '{$scenarioClass}' targets {$actualMachine} but delegation state expects {$expectedMachine}"
        );
    }

    /**
     * @param  array<int, string>  $errors
     */
    public static function invalidScenarioParams(string $scenarioClass, array $errors): self
    {
        return new self(
            message: "Scenario '{$scenarioClass}' has invalid parameters: ".implode(', ', $errors)
        );
    }

    public static function machineFaked(string $machineClass): self
    {
        return new self(
            message: "Cannot activate scenario on faked machine '{$machineClass}'. Scenarios require real execution."
        );
    }
}
