<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;

class ScenarioFailedException extends RuntimeException
{
    public function __construct(
        public readonly int $stepIndex,
        public readonly string $eventType,
        public readonly string $currentState,
        public readonly ?string $guardClass = null,
        public readonly ?string $rejectionReason = null,
    ) {
        $message = "Scenario failed at step {$stepIndex} (event: {$eventType}, state: {$currentState})";

        if ($guardClass !== null) {
            $message .= " — guard {$guardClass} rejected";
        }

        if ($rejectionReason !== null) {
            $message .= ": {$rejectionReason}";
        }

        parent::__construct($message);
    }
}
