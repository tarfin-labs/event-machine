<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * Class MachineValidationException.
 *
 * This exception is used to handle validation errors specific to the Machine class.
 * It extends the ValidationException class to provide more specific functionality.
 */
class MachineValidationException extends ValidationException {}
