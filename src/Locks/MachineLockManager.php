<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Locks;

use Illuminate\Support\Str;
use Illuminate\Support\Sleep;
use Tarfinlabs\EventMachine\Models\MachineStateLock;
use Illuminate\Database\UniqueConstraintViolationException;
use Tarfinlabs\EventMachine\Exceptions\MachineLockTimeoutException;

class MachineLockManager
{
    private static float $lastCleanupAt = 0;

    private const int CLEANUP_INTERVAL_SECONDS = 5;

    /**
     * Reset cleanup timer (for testing).
     */
    public static function resetCleanupTimer(): void
    {
        self::$lastCleanupAt = 0;
    }

    /**
     * Acquire a lock, waiting up to $timeout seconds.
     *
     * @param  int  $timeout  0 = immediate (fail if locked), >0 = block up to N seconds.
     * @param  int  $ttl  How long the lock lives before considered stale (seconds).
     *
     * @throws MachineLockTimeoutException When lock cannot be acquired within timeout.
     */
    public static function acquire(
        string $rootEventId,
        int $timeout = 0,
        int $ttl = 60,
        ?string $context = null,
    ): MachineLockHandle {
        $ownerId   = (string) Str::ulid();
        $start     = hrtime(true);
        $timeoutNs = $timeout * 1_000_000_000;

        while (true) {
            // Clean up expired locks (self-healing, rate-limited to avoid thundering herd)
            $now = microtime(true);
            if ($now - self::$lastCleanupAt >= self::CLEANUP_INTERVAL_SECONDS) {
                self::$lastCleanupAt = $now;
                MachineStateLock::query()
                    ->where('expires_at', '<', now())
                    ->delete();
            }

            try {
                MachineStateLock::create([
                    'root_event_id' => $rootEventId,
                    'owner_id'      => $ownerId,
                    'acquired_at'   => now(),
                    'expires_at'    => now()->addSeconds($ttl),
                    'context'       => $context,
                ]);

                return new MachineLockHandle($rootEventId, $ownerId);
            } catch (UniqueConstraintViolationException) {
                // Immediate mode: fail on first attempt
                if ($timeout === 0) {
                    $holder = MachineStateLock::find($rootEventId);

                    throw MachineLockTimeoutException::build(
                        rootEventId: $rootEventId,
                        timeout: $timeout,
                        holder: $holder instanceof MachineStateLock ? $holder : null,
                    );
                }

                // Blocking mode: check if we've exceeded timeout
                $elapsed = hrtime(true) - $start;
                if ($elapsed >= $timeoutNs) {
                    $holder = MachineStateLock::find($rootEventId);

                    throw MachineLockTimeoutException::build(
                        rootEventId: $rootEventId,
                        timeout: $timeout,
                        holder: $holder instanceof MachineStateLock ? $holder : null,
                    );
                }

                // Wait 100ms before retrying
                Sleep::usleep(100_000);
            }
        }
    }
}
