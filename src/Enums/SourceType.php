<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Enums;

/**
 * Enum class representing the possible event source types.
 */
enum SourceType: string
{
    case INTERNAL = 'internal';
    case EXTERNAL = 'external';
}
