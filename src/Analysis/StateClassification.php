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
}
