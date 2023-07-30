<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use LogicException;

class InvalidFinalStateDefinitionException extends LogicException
{
    public static function noChildStates(string $stateDefinition): self
    {
        return new self(message: "The final state `{$stateDefinition}` should not have child states. ".
            'Please revise your state machine definitions to ensure that final states are correctly configured without child states.'
        );
    }

    public static function noTransitions(string $stateDefinition): self
    {
        return new self(message: "The final state `{$stateDefinition}` should not have transitions. ".
            'Check your state machine configuration to ensure events are not dispatched when in a final state.'
        );
    }
}
