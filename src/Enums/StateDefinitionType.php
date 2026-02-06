<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Enums;

/**
 * Class StateDefinitionType.
 *
 * This class represents the state definition types for a state machine.
 */
enum StateDefinitionType: string
{
    case ATOMIC   = 'atomic';
    case COMPOUND = 'compound';
    case PARALLEL = 'parallel';
    case FINAL    = 'final';
}
