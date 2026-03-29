<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;

class BehaviorNotFakedException extends RuntimeException
{
    public static function build(string $behaviorClass): self
    {
        return new self('Behavior '.$behaviorClass.' was not faked.');
    }
}
