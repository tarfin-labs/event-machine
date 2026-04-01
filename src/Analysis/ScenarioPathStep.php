<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Analysis;

/**
 * A single state in a resolved scenario path.
 *
 * Represents one state between source and target, classified by its
 * structural role and annotated with metadata for scaffold generation.
 */
readonly class ScenarioPathStep
{
    /**
     * @param  string  $stateRoute  Full state route (e.g., 'car_sales.verification.findeks.running').
     * @param  string  $stateKey  Short state key for display (e.g., 'running').
     * @param  StateClassification  $classification  How this state should be handled in plan().
     * @param  ?string  $event  Event that enters this state (null for source state).
     * @param  array<string>  $guards  Guard class names on the transition into this state.
     * @param  array<string>  $actions  Action class names on the transition into this state.
     * @param  ?string  $invokeClass  Child machine or job FQCN (DELEGATION states only).
     * @param  array<string>  $availableEvents  Events available FROM this state (INTERACTIVE states).
     * @param  array<string>  $availableDoneStates  @done.{state} options (DELEGATION states).
     * @param  array<string>  $entryActions  Entry action class/key names on this state (for scaffold TODO hints).
     */
    public function __construct(
        public string $stateRoute,
        public string $stateKey,
        public StateClassification $classification,
        public ?string $event = null,
        public array $guards = [],
        public array $actions = [],
        public ?string $invokeClass = null,
        public array $availableEvents = [],
        public array $availableDoneStates = [],
        public array $entryActions = [],
    ) {}
}
