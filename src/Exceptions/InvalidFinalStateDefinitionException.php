<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use LogicException;

class InvalidFinalStateDefinitionException extends LogicException
{
    public static function childStates(string $stateDefinition): self
    {
        return new self("Final state `{$stateDefinition}` can not have child states.");
    }
}
