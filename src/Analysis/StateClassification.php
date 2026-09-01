<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Analysis;

/**
 * Classification of a state by its structural role in the machine definition.
 *
 * Used by MachineGraph, ScenarioPathResolver, and ScenarioScaffolder to determine
 * what kind of plan() entry each state needs during scenario scaffolding.
 */
enum StateClassification: string
{
    /**
     * Has @always transitions — machine passes through automatically.
     */
    case TRANSIENT = 'transient';

    /**
     * Has machine or job delegation (machine/job key).
     */
    case DELEGATION = 'delegation';

    /**
     * type === 'parallel' — concurrent regions.
     */
    case PARALLEL = 'parallel';

    /**
     * Has child states with an initial — enters initial child automatically.
     */
    case COMPOUND = 'compound';

    /**
     * No @always, no delegation, not parallel, not final, not compound — waits for external event.
     */
    case INTERACTIVE = 'interactive';

    /**
     * type === 'final' — terminal state.
     */
    case FINAL = 'final';

    /**
     * What a scenario must supply to traverse a state of this classification.
     *
     * Fixed, and deliberately not configurable: the values encode what the scenario has to
     * provide that the runtime cannot — an event to send, a child outcome to stand in for,
     * guards to pin inside a concurrent configuration — not a project preference.
     *
     * Only the ordering of the six values is claimed; the exchange rates between them are not.
     *
     * The match has no default arm on purpose. A seventh case added without a weight raises
     * UnhandledMatchError from inside path resolution, which is loud, rather than silently
     * pricing itself at 0, which is not.
     */
    public function weight(): int
    {
        return match ($this) {
            self::TRANSIENT, self::COMPOUND, self::FINAL => 0,
            self::INTERACTIVE                            => 1,
            self::DELEGATION                             => 3,
            self::PARALLEL                               => 5,
        };
    }
}
