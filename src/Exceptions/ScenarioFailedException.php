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

    public static function stateMismatch(string $expected, string $actual): self
    {
        return new self(
            stepIndex: -1,
            eventType: 'mid-flight-validation',
            currentState: $actual,
            rejectionReason: "Expected machine to be in state '{$expected}', but found '{$actual}'",
        );
    }
}
