<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;
use Illuminate\Http\JsonResponse;

/**
 * Thrown during scenario execution for runtime failures.
 */
class ScenarioFailedException extends RuntimeException
{
    public function __construct(
        public readonly string $eventType,
        public readonly string $currentState,
        public readonly string $rejectionReason,
        string $message,
    ) {
        parent::__construct(message: $message);
    }

    /**
     * Render the exception as an HTTP response.
     */
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
        ], 422);
    }

    public static function guardRejection(string $eventType, string $currentState, string $guardClass): self
    {
        $reason = "Guard {$guardClass} rejected";

        return new self(
            eventType: $eventType,
            currentState: $currentState,
            rejectionReason: $reason,
            message: $reason,
        );
    }

    public static function sourceMismatch(string $expected, string $actual): self
    {
        $reason = "Expected source '{$expected}', machine is at '{$actual}'";

        return new self(
            eventType: '',
            currentState: $actual,
            rejectionReason: $reason,
            message: $reason,
        );
    }

    public static function eventMismatch(string $expected, string $actual): self
    {
        $reason = "Expected event '{$expected}', received '{$actual}'";

        return new self(
            eventType: $actual,
            currentState: '',
            rejectionReason: $reason,
            message: $reason,
        );
    }

    public static function continueEventFailed(string $state, string $event, string $reason): self
    {
        $fullReason = "@continue event {$event} failed at state {$state}: {$reason}";

        return new self(
            eventType: $event,
            currentState: $state,
            rejectionReason: $fullReason,
            message: $fullReason,
        );
    }
}
