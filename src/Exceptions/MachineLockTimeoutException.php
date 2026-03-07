<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;
use Tarfinlabs\EventMachine\Models\MachineStateLock;

class MachineLockTimeoutException extends RuntimeException
{
    public static function build(
        string $rootEventId,
        int $timeout,
        ?MachineStateLock $holder = null,
    ): self {
        $message = "Failed to acquire lock for machine '{$rootEventId}'";

        if ($timeout === 0) {
            $message .= ' (immediate mode).';
        } else {
            $message .= " within {$timeout}s.";
        }

        if ($holder instanceof MachineStateLock) {
            $message .= " Held by: {$holder->owner_id} since {$holder->acquired_at} (context: {$holder->context})";
        }

        return new self($message);
    }
}
