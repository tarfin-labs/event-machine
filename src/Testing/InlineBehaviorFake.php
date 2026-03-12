<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Testing;

use Closure;
use PHPUnit\Framework\Assert;
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;

class InlineBehaviorFake
{
    /**
     * Registered inline behavior fakes.
     *
     * null value = spy (run original, record calls)
     * Closure value = fake (skip original, run replacement)
     *
     * @var array<string, Closure|null>
     */
    private static array $fakes = [];

    /** @var array<string, list<array>> Recorded invocations per key */
    private static array $calls = [];

    // ─── Registration ─────────────────────────────

    /**
     * Spy mode: record calls AND run the original closure.
     *
     * @throws \InvalidArgumentException If $key is a class-based behavior FQCN
     */
    public static function spy(string $key): void
    {
        self::guardAgainstFqcn($key);

        self::$fakes[$key] = null;
        self::$calls[$key] = [];
    }

    /**
     * Fake mode: record calls, skip original, run replacement (default: variadic no-op).
     *
     * IMPORTANT: The replacement receives the same positional parameters that the
     * engine would inject into the original closure (based on the original's signature
     * via ReflectionFunction). The default no-op uses variadic args to safely absorb
     * any parameter combination. Custom replacements must accept variadic args or
     * match the original closure's parameter signature.
     *
     * @throws \InvalidArgumentException If $key is a class-based behavior FQCN
     */
    public static function fake(string $key, ?Closure $replacement = null): void
    {
        self::guardAgainstFqcn($key);

        self::$fakes[$key] = $replacement ?? static fn (mixed ...$args): null => null;
        self::$calls[$key] = [];
    }

    /**
     * Fake a guard/calculator with a specific return value.
     *
     * Convenience method for the common pattern of faking a guard to return true/false.
     */
    public static function shouldReturn(string $key, mixed $value): void
    {
        self::fake($key, static fn (mixed ...$args): mixed => $value);
    }

    // ─── Interception ─────────────────────────────

    /**
     * Called at invocation sites. Records the call if tracked.
     *
     * @param  string  $key  The bare behavior key (colon-stripped by the engine before this call)
     * @param  array  $parameters  The positional parameters injected by injectInvokableBehaviorParameters()
     *
     * @return bool True if the original should be SKIPPED (fake mode).
     */
    public static function intercept(string $key, array $parameters): bool
    {
        if (!array_key_exists($key, self::$fakes)) {
            return false;
        }

        self::$calls[$key][] = $parameters;

        // null = spy (don't skip), Closure = fake (skip original)
        return self::$fakes[$key] instanceof Closure;
    }

    /**
     * Get the replacement closure for a faked behavior.
     */
    public static function getReplacement(string $key): ?Closure
    {
        return self::$fakes[$key];
    }

    // ─── Inspection ───────────────────────────────

    public static function isFaked(string $key): bool
    {
        return array_key_exists($key, self::$fakes);
    }

    /**
     * @return list<array> All recorded invocations for the key
     */
    public static function getCalls(string $key): array
    {
        return self::$calls[$key] ?? [];
    }

    // ─── Assertions ───────────────────────────────

    public static function assertRan(string $key): void
    {
        Assert::assertArrayHasKey($key, self::$fakes,
            "Inline behavior '{$key}' was not faked or spied. Call InlineBehaviorFake::spy('{$key}') or faking(['{$key}']) first."
        );
        Assert::assertNotEmpty(self::$calls[$key],
            "Expected inline behavior '{$key}' to have been called, but it was not."
        );
    }

    public static function assertNotRan(string $key): void
    {
        Assert::assertArrayHasKey($key, self::$fakes,
            "Inline behavior '{$key}' was not faked or spied."
        );
        Assert::assertEmpty(self::$calls[$key],
            "Expected inline behavior '{$key}' NOT to have been called, but it was called ".count(self::$calls[$key]).' time(s).'
        );
    }

    public static function assertRanTimes(string $key, int $expected): void
    {
        Assert::assertArrayHasKey($key, self::$fakes,
            "Inline behavior '{$key}' was not faked or spied."
        );
        Assert::assertCount($expected, self::$calls[$key],
            "Expected inline behavior '{$key}' to run {$expected} time(s), ran ".count(self::$calls[$key]).'.'
        );
    }

    /**
     * Assert the behavior was called with parameters matching the callback.
     *
     * The callback receives the FULL injected parameter array as a single argument
     * (not spread). This avoids ArgumentCountError since the parameter count varies
     * per closure signature.
     *
     * Usage:
     *   InlineBehaviorFake::assertRanWith('myAction', function (array $params) {
     *       [$state] = $params;  // destructure based on the original closure's signature
     *       return $state->context->get('total') === 100;
     *   });
     */
    public static function assertRanWith(string $key, Closure $callback): void
    {
        Assert::assertArrayHasKey($key, self::$fakes,
            "Inline behavior '{$key}' was not faked or spied."
        );

        $matched = false;
        foreach (self::$calls[$key] as $callArgs) {
            if ($callback($callArgs) === true) {
                $matched = true;
                break;
            }
        }

        Assert::assertTrue($matched,
            "Expected inline behavior '{$key}' to have been called with matching arguments."
        );
    }

    // ─── Cleanup ──────────────────────────────────

    public static function reset(string $key): void
    {
        unset(self::$fakes[$key], self::$calls[$key]);
    }

    public static function resetAll(): void
    {
        self::$fakes = [];
        self::$calls = [];
    }

    // ─── Internal ─────────────────────────────────

    /**
     * Prevent accidental registration of class-based behavior FQCNs.
     *
     * If a developer calls InlineBehaviorFake::fake('App\Actions\SendEmail'),
     * this would silently register but never intercept (the engine resolves FQCNs
     * through the container, not through inline lookup). The error message guides
     * toward the correct API.
     */
    private static function guardAgainstFqcn(string $key): void
    {
        if (class_exists($key) && is_subclass_of($key, InvokableBehavior::class)) {
            throw new \InvalidArgumentException(
                "'{$key}' is a class-based behavior (InvokableBehavior subclass). "
                ."Use {$key}::spy() or {$key}::fake() instead of InlineBehaviorFake."
            );
        }
    }
}
