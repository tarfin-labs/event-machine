<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;

class NoParentMachineException extends RuntimeException
{
    public static function sendToParent(): self
    {
        return new self('Cannot sendToParent: this machine was not invoked by a parent.');
    }

    public static function dispatchToParent(): self
    {
        return new self('Cannot dispatchToParent: this machine was not invoked by a parent.');
    }
}
