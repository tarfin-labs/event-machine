<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scenarios;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Tarfinlabs\EventMachine\Exceptions\ScenarioConfigurationException;

/**
 * Base class for machine scenarios.
 *
 * A scenario defines a journey through a machine — from a source state,
 * via a triggering event, to a target state — with behavior overrides
 * and delegation outcomes declared in plan().
 */
abstract class MachineScenario
{
    /** Which machine this scenario targets (class-string). */
    protected string $machine;

    /** Full state route — the state the machine must be in BEFORE the event (start). */
    protected string $source;

    /** The event that triggers this scenario (class-string or event type string). */
    protected string $event;

    /** Full state route — where the machine should end up after execution (end). */
    protected string $target;

    /** Human-readable description shown in endpoint responses. */
    protected string $description;

    /** Validated scenario parameters. */
    private array $resolvedParams = [];

    public function __construct()
    {
        $this->validateProperties();
    }

    /**
     * The scenario's plan — every key is a full state route.
     * Value type determines meaning: array → behavior overrides (may include @continue),
     * string starting with @ → delegation outcome,
     * MachineScenario class → child scenario reference.
     */
    protected function plan(): array
    {
        return [];
    }

    /**
     * Scenario parameter definitions.
     * Each key is a parameter name. Value is either:
     *   - plain array: Laravel validation rules only (e.g., ['required', 'string'])
     *   - assoc array: rich definition with optional type/values/label + rules key.
     */
    protected function params(): array
    {
        return [];
    }

    /**
     * Access a validated scenario parameter.
     */
    protected function param(string $key, mixed $default = null): mixed
    {
        return $this->resolvedParams[$key] ?? $default;
    }

    /**
     * Hydrate scenario with validated parameters.
     * Extracts validation rules from both plain and rich param definitions,
     * runs Laravel validation, stores resolved values.
     */
    public function hydrateParams(array $rawParams): void
    {
        $paramDefs = $this->params();

        if ($paramDefs === []) {
            $this->resolvedParams = [];

            return;
        }

        $rules = $this->extractValidationRules($paramDefs);

        $validator = Validator::make($rawParams, $rules);

        if ($validator->fails()) {
            throw ScenarioConfigurationException::invalidScenarioParams(
                scenarioClass: static::class,
                errors: $validator->errors()->all(),
            );
        }

        $this->resolvedParams = $validator->validated();
    }

    /**
     * Derive slug from class name: AtCheckingProtocolScenario → at-checking-protocol-scenario.
     */
    public function slug(): string
    {
        $shortName = class_basename(static::class);

        return Str::kebab($shortName);
    }

    /**
     * Get the resolved machine class (method-first, property-fallback).
     */
    public function machine(): string
    {
        return $this->machine;
    }

    /**
     * Get the resolved source state route.
     */
    public function source(): string
    {
        return $this->source;
    }

    /**
     * Get the resolved event.
     */
    public function event(): string
    {
        return $this->event;
    }

    /**
     * Get the resolved target state route.
     */
    public function target(): string
    {
        return $this->target;
    }

    /**
     * Get the description.
     */
    public function description(): string
    {
        return $this->description;
    }

    /**
     * Get the resolved plan.
     */
    public function resolvedPlan(): array
    {
        return $this->plan();
    }

    /**
     * Get the resolved params definition (for endpoint response serialization).
     */
    public function resolvedParams(): array
    {
        return $this->params();
    }

    /**
     * Extract validation rules from param definitions.
     * Plain array = rules directly. Rich definition = extract 'rules' key.
     */
    private function extractValidationRules(array $paramDefs): array
    {
        $rules = [];

        foreach ($paramDefs as $key => $definition) {
            $rules[$key] = $this->isRichDefinition($definition) ? $definition['rules'] ?? [] : $definition;
        }

        return $rules;
    }

    /**
     * Detect rich definition (assoc array with 'rules' key) vs plain rules array.
     */
    private function isRichDefinition(mixed $definition): bool
    {
        return is_array($definition) && array_key_exists('rules', $definition);
    }

    /**
     * Validate that all required properties are set.
     */
    private function validateProperties(): void
    {
        foreach (['machine', 'source', 'event', 'target', 'description'] as $property) {
            if (!isset($this->{$property}) || $this->{$property} === '') {
                throw ScenarioConfigurationException::missingProperty(
                    class: static::class,
                    property: $property,
                );
            }
        }
    }
}
