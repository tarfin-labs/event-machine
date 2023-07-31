<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

/**
 * Class StateDefinitionType.
 *
 * This class represents the state definition types for a state machine.
 */
enum StateDefinitionType: string
{
    case ATOMIC   = 'atomic';
    case COMPOUND = 'compound';
    case FINAL    = 'final';
}
