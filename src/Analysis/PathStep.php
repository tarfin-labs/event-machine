<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Analysis;

/**
 * A single step in an enumerated machine path.
 *
 * Represents one state visit during DFS traversal, along with the event
 * that triggered the transition into this state and any metadata.
 */
readonly class PathStep
{
    /**
     * @param  string  $stateId  Full state definition ID (e.g. "machine.idle").
     * @param  string  $stateKey  Short state key (e.g. "idle").
     * @param  ?string  $event  Event type that triggered this step (null for initial state).
     * @param  ?int  $branchIndex  Which branch of a guarded transition was taken.
     * @param  array<string>  $guards  Guard names evaluated on this step.
     * @param  array<string>  $actions  Action names executed on this step.
     * @param  ?string  $timerType  'after' or 'every' if this step was timer-triggered, null otherwise.
     * @param  ?string  $invokeType  '@done', '@fail', '@timeout', or '@done.{state}' if machine invoke, null otherwise.
     */
    public function __construct(
        public string $stateId,
        public string $stateKey,
        public ?string $event = null,
        public ?int $branchIndex = null,
        public array $guards = [],
        public array $actions = [],
        public ?string $timerType = null,
        public ?string $invokeType = null,
    ) {}
}
