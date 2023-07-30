<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

enum StateDefinitionType: string
{
    case ATOMIC   = 'atomic';
    case COMPOUND = 'compound';
    case FINAL    = 'final';
}
