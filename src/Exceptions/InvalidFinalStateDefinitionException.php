<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use LogicException;

class InvalidFinalStateDefinitionException extends LogicException
{
    public static function noChildStates(string $stateDefinition): self
    {
        return new self("Final state `{$stateDefinition}` can not have child states.");
    }

    public static function noTransitions(string $stateDefinition): self
    {
        return new self("Final state `{$stateDefinition}` can not have transitions.");
    }
}
