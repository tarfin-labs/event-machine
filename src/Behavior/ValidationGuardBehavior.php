<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

/**
 * An abstract class that represents a validation guard behavior.
 *
 * This class extends the GuardBehavior class and provides a basic structure for
 * implementing validation guard behaviors. It defines a public property `$errorMessage`
 * that can be used to store an error message and an abstract function `__invoke` that
 * must be implemented by the child classes.
 */
abstract class ValidationGuardBehavior extends GuardBehavior
{
    /** @var string|null Holds an error message, which is initially null. */
    public ?string $errorMessage = null;
}
