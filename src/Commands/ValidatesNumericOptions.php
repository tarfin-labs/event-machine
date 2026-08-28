<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

/**
 * Reject a numeric option that PHP would silently coerce to zero.
 *
 * `(int) 'abc'` and `(int) ''` are both `0`, and every option this package casts that
 * way means something at zero: a ceiling of zero truncates everything, an index of zero
 * selects the first item. So a typo does not fail — it quietly changes what the command
 * did, and `--path=abc` will happily scaffold path 0 and report "Created:". This mirrors
 * the check `machine:coverage --min` already carries, whose reasoning is the same: a gate
 * disabled by a typo is worse than no gate.
 */
trait ValidatesNumericOptions
{
    /**
     * Read an integer option, or null when it is not a valid integer ≥ $min.
     *
     * Returns null after printing the error, so the caller can `return self::FAILURE`.
     */
    protected function integerOption(string $name, int $min = 0): ?int
    {
        $raw = $this->option($name);

        // Console input arrives as a string, but Artisan::call() passes whatever the
        // caller wrote — an int stays an int. Both are valid; anything else is not.
        if (is_int($raw)) {
            $value = $raw;
        } elseif (is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1) {
            $value = (int) $raw;
        } else {
            $shown = is_scalar($raw) ? (string) $raw : gettype($raw);
            $this->error("--{$name} must be an integer, got '{$shown}'.");

            return null;
        }

        if ($value < $min) {
            $this->error("--{$name} must be at least {$min}, got {$value}.");

            return null;
        }

        return $value;
    }
}
