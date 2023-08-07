<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Enums;

/**
 * Represents a transition property.
 */
enum TransitionProperty: string
{
    case Normal  = '@normal';
    case Always  = '@always';
    case Guarded = '@guarded';
}
