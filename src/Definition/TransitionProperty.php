<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

/**
 * Represents a transition property.
 */
enum TransitionProperty: string
{
    case Normal  = '@normal';
    case Always  = '@always';
    case Guarded = '@guarded';
}
