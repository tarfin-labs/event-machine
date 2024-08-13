<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * Class MachineEventValidationException.
 *
 * This class represents an exception that is thrown when a validation error occurs while processing a machine event.
 */
class MachineEventValidationException extends ValidationException
{
}
