<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;

class BehaviorNotFoundException extends RuntimeException
{
    public static function build(string $behaviorType): self
    {
        return new self("Behavior of type `{$behaviorType}` not found.");
    }
}
