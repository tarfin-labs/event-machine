<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * Represents an exception thrown when the validation of the machine context fails.
 */
class MachineContextValidationException extends ValidationException {}
